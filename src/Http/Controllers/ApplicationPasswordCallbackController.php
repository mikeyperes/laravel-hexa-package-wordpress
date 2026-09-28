<?php

namespace hexa_package_wordpress\Http\Controllers;

use hexa_package_wordpress\Connections\ApplicationPasswordAuthorization;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use InvalidArgumentException;

/** Receives WordPress's Authorize Application redirect. Shows no secret. */
class ApplicationPasswordCallbackController extends Controller
{
    public function __invoke(Request $request, string $state, ApplicationPasswordAuthorization $authorization): Response
    {
        try {
            $result = $authorization->complete($state, $request->query());
            $message = 'Connected '.$result['host'].' as '.$result['username'].'. You can close this tab.';
            $status = 200;
        } catch (InvalidArgumentException $e) {
            $message = $e->getMessage();
            $status = 422;
        }

        return response('<!doctype html><meta charset="utf-8"><title>WordPress connection</title><p>'.e($message).'</p>', $status)
            ->header('Content-Type', 'text/html; charset=utf-8')
            ->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
