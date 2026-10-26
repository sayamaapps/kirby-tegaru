<?php

// Options Tegaru reads, relative to the plugin: `sayamaapps.tegaru.<key>` (OPT-1).
// Each value is null on purpose. Options::get() gives the default at every read,
// and a default here would hide the 1.x spelling `tegaru.<key>` (fallback).
return [
	'active'         => null,
	'language'       => null,
	'maxEdge'        => null,
	'maxPixels'      => null,
	'maxUploadBytes' => null,
	'minBytes'       => null,
	'quality'        => null,
];
