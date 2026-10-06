# frozen_string_literal: true

require 'json'
require 'net/http'
require 'openssl'
require 'rbconfig'
require 'securerandom'
require 'time'
require 'uri'

# mini-apm client for Ruby apps. Standard library only.
#
#   apm = MiniApm::Client.new(endpoint: 'https://host', api_key: 'apm_...', app_version: '1.2.0')
#   apm.session_start
#   apm.track('export_pdf')
#   begin
#     risky
#   rescue => e
#     apm.capture_exception(e)
#   end
#   apm.flush
module MiniApm
  MAX_EVENTS_PER_REQUEST = 100 # the API accepts at most 100 events per batch
  MAX_QUEUE = 500              # oldest events are dropped beyond this
  MAX_MESSAGE = 2000
  MAX_STACK = 20_000

  class Client
    attr_reader :queue

    # +retry_delay+ is how many seconds automatic sending pauses after a failure, so an
    # app is not slowed down by a dead server. A manual +flush+ always tries.
    def initialize(endpoint:, api_key:, app_version:, user_ref: nil, env: nil, max_batch_size: 20,
                   open_timeout: 3, read_timeout: 5, retry_delay: 10)
      raise ArgumentError, 'endpoint, api_key and app_version are required' if [endpoint, api_key, app_version].any? { |v| v.to_s.empty? }

      @uri = URI("#{endpoint.to_s.sub(%r{/+\z}, '')}/api/v1/events")
      @api_key = api_key
      @app_version = app_version
      @user_ref = user_ref || "u_#{SecureRandom.hex(3)}"
      @env = env || self.class.collect_env
      @max_batch_size = [max_batch_size, MAX_EVENTS_PER_REQUEST].min
      @open_timeout = open_timeout
      @read_timeout = read_timeout
      @retry_delay = retry_delay
      @retry_at = Time.at(0)
      @queue = []
    end

    # Opens a session with the machine data. Call once when the app starts.
    def session_start
      enqueue(type: 'session_start', env: @env)
    end

    # Records that a feature was used.
    def track(name, properties = nil)
      enqueue(type: 'feature_used', name: name, properties: properties)
    end

    # Records an error, or a crash when +fatal+ is true.
    def capture_exception(exception, fatal: false)
      enqueue(
        type: fatal ? 'crash' : 'error',
        message: "#{exception.class}: #{exception.message}"[0, MAX_MESSAGE],
        stack: exception.backtrace&.join("\n")&.[](0, MAX_STACK)
      )
    end

    # Sends everything queued. Returns true only when the API accepted every event.
    # A batch the API rejects for good (a bad key, invalid data) is dropped and gives
    # false; a batch that failed for a passing reason stays queued for the next flush.
    def flush
      accepted = true

      until @queue.empty?
        batch = @queue.shift(MAX_EVENTS_PER_REQUEST)

        case post(batch)
        when :accepted then next
        when :rejected then accepted = false
        else
          @queue.unshift(*batch)
          @retry_at = Time.now + @retry_delay
          return false
        end
      end

      accepted
    end

    # Machine data sent with the session. Anything that cannot be read is left out.
    def self.collect_env
      { os: detect_os, ram_mb: detect_ram_mb, gpu: ENV['MINI_APM_GPU'] }.compact
    end

    def self.detect_os
      case RbConfig::CONFIG['host_os']
      when /mswin|mingw|cygwin/ then 'Windows'
      when /darwin/ then 'macOS'
      when /linux/ then 'Linux'
      end
    end

    def self.detect_ram_mb
      case RbConfig::CONFIG['host_os']
      when /linux/
        File.read('/proc/meminfo')[/MemTotal:\s+(\d+) kB/, 1].to_i / 1024
      when /darwin/
        `sysctl -n hw.memsize`.to_i / 1024 / 1024
      when /mswin|mingw|cygwin/
        `powershell -NoProfile -Command "(Get-CimInstance Win32_ComputerSystem).TotalPhysicalMemory"`.to_i / 1024 / 1024
      end&.then { |mb| mb.positive? ? mb : nil }
    rescue StandardError
      nil
    end

    private

    def enqueue(event)
      @queue << event.compact.merge(
        occurred_at: Time.now.utc.iso8601(3),
        app_version: @app_version,
        user_ref: @user_ref
      )
      @queue.shift(@queue.size - MAX_QUEUE) if @queue.size > MAX_QUEUE
      flush if @queue.size >= @max_batch_size && Time.now >= @retry_at
    end

    # :accepted, :rejected (a bad key or invalid data will never succeed) or :retry.
    def post(events)
      request = Net::HTTP::Post.new(@uri, 'Content-Type' => 'application/json', 'Accept' => 'application/json',
                                          'Authorization' => "Bearer #{@api_key}")
      request.body = JSON.generate(events: events)

      response = Net::HTTP.start(@uri.host, @uri.port, use_ssl: @uri.scheme == 'https',
                                                       open_timeout: @open_timeout, read_timeout: @read_timeout) do |http|
        http.request(request)
      end

      code = response.code.to_i
      if code.between?(200, 299) then :accepted
      elsif code.between?(400, 499) && code != 429 then :rejected
      else :retry
      end
    rescue SystemCallError, Timeout::Error, IOError, SocketError, OpenSSL::SSL::SSLError
      :retry
    end
  end
end
