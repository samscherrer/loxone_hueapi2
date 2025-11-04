#!/bin/sh

# Simple CGI wrapper to serve the static HTML landing page for the plugin.
echo "Content-type: text/html"
echo
cat "$(dirname "$0")/index.html"
