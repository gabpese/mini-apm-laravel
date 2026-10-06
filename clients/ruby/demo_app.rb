#!/usr/bin/env ruby
# frozen_string_literal: true

# A fake desktop app for the terminal. It opens sessions, uses random features,
# now and then raises an error, and sends everything to mini-apm.
#
#   ruby demo_app.rb --key apm_... --url http://mini-apm-laravel.test --sessions 10
#
# The key and the URL can also come from MINI_APM_KEY and MINI_APM_URL.

require 'optparse'
require_relative 'lib/mini_apm'

FEATURES = %w[exportar_pdf importar_csv compartilhar modo_escuro busca_avancada sincronizar].freeze

options = {
  url: ENV.fetch('MINI_APM_URL', 'http://localhost:8000'),
  key: ENV.fetch('MINI_APM_KEY', nil),
  version: '1.2.0',
  sessions: 5,
  error_rate: 0.2,
  crash_rate: 0.05,
  pause: 0.2
}

OptionParser.new do |opts|
  opts.banner = 'Usage: ruby demo_app.rb [options]'
  opts.on('--url URL', 'mini-apm server URL') { |v| options[:url] = v }
  opts.on('--key KEY', 'project API key') { |v| options[:key] = v }
  opts.on('--version VERSION', "version of this fake app (default #{options[:version]})") { |v| options[:version] = v }
  opts.on('--sessions N', Integer, "how many users to simulate (default #{options[:sessions]})") { |v| options[:sessions] = v }
  opts.on('--error-rate RATE', Float, 'chance of a handled error per session') { |v| options[:error_rate] = v }
  opts.on('--crash-rate RATE', Float, 'chance of a crash per session') { |v| options[:crash_rate] = v }
  opts.on('--pause SECONDS', Float, 'pause between actions') { |v| options[:pause] = v }
end.parse!

abort 'An API key is required: pass --key or set MINI_APM_KEY.' if options[:key].to_s.empty?

# Something that fails the way real code does, so the stack trace is real.
class Exporter
  def render(_document)
    nil.render_chart
  end
end

sent = 0
accepted = true

options[:sessions].times do |n|
  apm = MiniApm::Client.new(endpoint: options[:url], api_key: options[:key], app_version: options[:version])
  apm.session_start
  puts "session #{n + 1}/#{options[:sessions]} opened on #{options[:version]}"

  rand(2..6).times do
    feature = FEATURES.sample
    apm.track(feature)
    puts "  used #{feature}"
    sleep options[:pause]
  end

  if rand < options[:error_rate]
    begin
      Exporter.new.render(:report)
    rescue StandardError => e
      apm.capture_exception(e)
      puts "  handled error: #{e.message[0, 60]}"
    end
  end

  if rand < options[:crash_rate]
    begin
      raise 'Out of memory while loading the project'
    rescue StandardError => e
      apm.capture_exception(e, fatal: true)
      puts '  CRASH'
    end
  end

  sent += apm.queue.size
  accepted &&= apm.flush
end

if accepted
  puts "done: #{sent} events accepted by #{options[:url]}"
else
  warn "some events were not accepted by #{options[:url]}. Is the server running and the key valid?"
  exit 1
end
