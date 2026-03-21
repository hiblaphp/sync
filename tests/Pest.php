<?php

declare(strict_types=1);

use Hibla\Promise\Promise;

uses()->beforeEach(function () {
    Promise::setRejectionHandler(null);
});
