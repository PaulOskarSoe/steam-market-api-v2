<?php

namespace SteamApi\Interfaces;

interface RequestInterface
{
    public function getUrl();

    public function getHeaders() : array;

    public function call();

    public function getRequestMethod();
}