<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\People;

use App\Http\Controllers\Controller;
use App\Http\People\PeopleServiceFactory;
use Illuminate\Http\Request;

abstract class PeopleBaseController extends Controller
{
    public function __construct(protected PeopleServiceFactory $services)
    {
    }

    /** @template T @param class-string<T> $class @return T */
    protected function service(string $class): object
    {
        return $this->services->make($class);
    }

    protected function actor(Request $request): int
    {
        return (int) $request->user()->getAuthIdentifier();
    }

    protected function session(Request $request): int
    {
        return (int) $request->attributes->get('auth_session_id');
    }
}
