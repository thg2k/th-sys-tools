#!/usr/bin/env php
<?php

$data = file_get_contents("php://stdin");

$obj = json_decode($data);

print json_encode($obj, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
