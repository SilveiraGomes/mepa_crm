<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Academy;

use App\Http\Academy\AcademyRouteResolver;
use App\Http\Academy\AcademyServiceFactory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Academy\AcademyRequest;

abstract class AcademyController extends Controller
{
    public function __construct(protected AcademyServiceFactory $services, protected AcademyRouteResolver $routes)
    {
    }

    protected function service(string $class): object { return $this->services->make($class); }
    protected function actor(AcademyRequest $request): int { return (int) $request->user()->getAuthIdentifier(); }
    protected function session(AcademyRequest $request): int { return (int) $request->attributes->get('auth_session_id'); }
    protected function id(string $table, mixed $value): int { return $this->routes->id($table, $value); }
}
