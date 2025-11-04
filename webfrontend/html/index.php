<?php
// LoxBerry prefers PHP as the default DirectoryIndex. Forward to the static UI.
header('Content-Type: text/html; charset=utf-8');
readfile(__DIR__ . '/index.html');
