# frozen_string_literal: true

require 'minitest/autorun'
require 'socket'
require_relative '../lib/mini_apm'

# A tiny HTTP server on a free local port, so the tests exercise the real Net::HTTP path.
class FakeApi
  attr_reader :requests, :port

  def initialize(statuses = [202])
    @statuses = statuses
    @requests = []
    @server = TCPServer.new('127.0.0.1', 0)
    @port = @server.addr[1]
    @thread = Thread.new { serve }
  end

  def stop
    @thread.kill
    @server.close
  end

  private

  def serve
    loop do
      socket = @server.accept
      request_line = socket.gets
      headers = {}
      while (line = socket.gets) && line != "\r\n"
        key, value = line.split(': ', 2)
        headers[key.downcase] = value.strip
      end
      body = socket.read(headers['content-length'].to_i)
      @requests << { line: request_line.strip, headers: headers, body: JSON.parse(body) }

      status = @statuses.size > 1 ? @statuses.shift : @statuses.first
      socket.write("HTTP/1.1 #{status} X\r\nContent-Length: 2\r\nConnection: close\r\n\r\n{}")
      socket.close
    end
  end
end

class ClientTest < Minitest::Test
  ENV_DATA = { os: 'Windows 11', ram_mb: 16_384, gpu: 'GTX 1660' }.freeze

  def teardown
    @api&.stop
  end

  def client(statuses = [202], **options)
    @api = FakeApi.new(statuses)
    MiniApm::Client.new(endpoint: "http://127.0.0.1:#{@api.port}/", api_key: 'apm_key', app_version: '1.2.0',
                        user_ref: 'u_test', env: ENV_DATA, **options)
  end

  def test_requires_endpoint_key_and_version
    assert_raises(ArgumentError) { MiniApm::Client.new(endpoint: 'x', api_key: '', app_version: '1') }
  end

  def test_session_start_sends_the_machine_data
    apm = client
    apm.session_start

    assert apm.flush

    event = @api.requests.first[:body]['events'].first
    assert_equal 'session_start', event['type']
    assert_equal '1.2.0', event['app_version']
    assert_equal 'u_test', event['user_ref']
    assert_equal({ 'os' => 'Windows 11', 'ram_mb' => 16_384, 'gpu' => 'GTX 1660' }, event['env'])
    refute_nil Time.iso8601(event['occurred_at'])
  end

  def test_posts_to_the_versioned_endpoint_with_the_key_as_a_bearer_token
    apm = client
    apm.track('exportar_pdf')
    apm.flush

    request = @api.requests.first
    assert_equal 'POST /api/v1/events HTTP/1.1', request[:line]
    assert_equal 'Bearer apm_key', request[:headers]['authorization']
    assert_equal 'application/json', request[:headers]['content-type']
  end

  def test_track_records_a_feature_with_its_properties
    apm = client
    apm.track('exportar_pdf', pages: 3)
    apm.flush

    event = @api.requests.first[:body]['events'].first
    assert_equal 'feature_used', event['type']
    assert_equal 'exportar_pdf', event['name']
    assert_equal({ 'pages' => 3 }, event['properties'])
  end

  def test_capture_exception_records_an_error_or_a_crash_when_fatal
    apm = client
    begin
      raise ArgumentError, 'boom'
    rescue StandardError => e
      apm.capture_exception(e)
      apm.capture_exception(e, fatal: true)
    end
    apm.flush

    events = @api.requests.first[:body]['events']
    assert_equal %w[error crash], events.map { |ev| ev['type'] }
    assert_equal 'ArgumentError: boom', events.first['message']
    assert_match(/client_test\.rb/, events.first['stack'])
  end

  def test_truncates_a_message_and_a_stack_beyond_the_api_limits
    apm = client
    error = RuntimeError.new('x' * 5000)
    error.set_backtrace(['y' * 30_000])
    apm.capture_exception(error)
    apm.flush

    event = @api.requests.first[:body]['events'].first
    assert_equal 2000, event['message'].size
    assert_equal 20_000, event['stack'].size
  end

  def test_sends_by_itself_once_max_batch_size_events_are_queued
    apm = client(max_batch_size: 3)
    2.times { |i| apm.track("f#{i}") }
    assert_empty @api.requests

    apm.track('f2')

    assert_equal 1, @api.requests.size
    assert_equal 3, @api.requests.first[:body]['events'].size
  end

  def test_splits_a_large_queue_into_requests_of_at_most_100_events
    apm = client(max_batch_size: 1000)
    250.times { |i| apm.track("f#{i}") }
    apm.flush

    assert_equal [100, 100, 50], @api.requests.map { |r| r[:body]['events'].size }
  end

  def test_keeps_the_events_and_retries_when_the_server_fails
    apm = client([503, 202])
    apm.track('a')

    refute apm.flush
    assert_equal 1, apm.queue.size

    assert apm.flush
    assert_empty apm.queue
  end

  def test_keeps_the_events_when_the_server_is_unreachable
    apm = MiniApm::Client.new(endpoint: 'http://127.0.0.1:1', api_key: 'k', app_version: '1', env: {}, open_timeout: 1)
    apm.track('a')

    refute apm.flush
    assert_equal 1, apm.queue.size
  end

  def test_drops_a_batch_the_api_rejects_for_good_such_as_a_bad_key
    apm = client([401])
    apm.track('a')

    refute apm.flush # not accepted...
    assert_empty apm.queue # ...but not kept either
  end

  def test_retries_after_rate_limiting
    apm = client([429])
    apm.track('a')
    apm.flush

    assert_equal 1, apm.queue.size
  end

  def test_never_queues_more_than_500_events
    apm = client([503], max_batch_size: 1000)
    700.times { |i| apm.track("f#{i}") }

    assert_equal 500, apm.queue.size
  end

  def test_pauses_automatic_sending_after_a_failure
    apm = client([503], max_batch_size: 2, retry_delay: 60)
    5.times { |i| apm.track("f#{i}") }

    assert_equal 1, @api.requests.size # the other tracks did not try again

    apm.flush # a manual flush always tries
    assert_equal 2, @api.requests.size
  end

  def test_sends_by_itself_again_once_the_pause_is_over
    apm = client([503, 202], max_batch_size: 2, retry_delay: 0)
    2.times { |i| apm.track("f#{i}") }
    apm.track('f2')

    assert_equal 2, @api.requests.size
    assert_empty apm.queue
  end

  def test_collect_env_never_raises
    assert_kind_of Hash, MiniApm::Client.collect_env
  end
end
