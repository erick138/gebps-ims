<?php

namespace App\Core;

abstract class Controller
{
    public Request $request;
    public Response $response;

    public function __construct()
    {
        $this->request  = Kernel::$request;
        $this->response = new Response();
    }
}
