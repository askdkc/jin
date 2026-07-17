<?php

use App\Mcp\Servers\JinServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::local('jin', JinServer::class);
