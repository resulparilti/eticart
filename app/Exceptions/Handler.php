<?php

namespace App\Exceptions;

use App\Support\PanelNavigation;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of exception types with their corresponding custom log levels.
     *
     * @var array<class-string<\Throwable>, \Psr\Log\LogLevel::*>
     */
    protected $levels = [
        //
    ];

    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<\Throwable>>
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });

        $this->renderable(function (HttpException $e, Request $request) {
            if ($e->getStatusCode() !== 419) {
                return null;
            }

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Oturum doğrulaması yenilenmeli. Sayfayı yenileyip tekrar deneyin.',
                ], 419);
            }

            if (Auth::check()) {
                return redirect()
                    ->to(PanelNavigation::last($request))
                    ->with('error', 'Güvenlik doğrulaması yenilenmeli. Sayfayı yenileyip işlemi tekrar deneyin.');
            }

            return redirect()
                ->route('login')
                ->with('error', 'Oturum süresi doldu. Lütfen tekrar giriş yapın.');
        });
    }

    /**
     * Log kanalı yazılamazsa (izin hatası) isteği 500 yapma.
     */
    public function report(Throwable $e): void
    {
        try {
            parent::report($e);
        } catch (Throwable) {
            //
        }
    }
}
