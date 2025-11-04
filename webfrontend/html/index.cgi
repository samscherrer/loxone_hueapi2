#!/bin/sh

# Simple CGI wrapper to serve the static HTML landing page for the plugin.
printf 'Content-type: text/html; charset=utf-8\r\n\r\n'
cat "$(dirname "$0")/index.html"
