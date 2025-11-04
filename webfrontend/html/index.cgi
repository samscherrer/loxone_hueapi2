#!/bin/bash
# Simple CGI wrapper so LoxBerry loads the static UI without showing a directory listing.
echo "Content-type: text/html"
echo
exec /bin/cat "$(dirname "$0")/index.html"
