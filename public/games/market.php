<?php
// Neutral endpoint name avoids browser content blockers that classify XHR URLs
// containing "/stock/" as third-party market trackers. The implementation stays
// in the stock module so page and endpoint share all validation and settlement.
require __DIR__ . '/stock/index.php';

