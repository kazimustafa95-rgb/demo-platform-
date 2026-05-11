<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PersonaIdentityVerificationService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class IdentityVerificationController extends Controller
{
    public function start(Request $request, PersonaIdentityVerificationService $persona): \Illuminate\Http\JsonResponse
    {
        if (!$persona->isEnabled()) {
            return response()->json([
                'message' => 'Persona identity verification is not enabled for this environment.',
            ], 409);
        }

        if (!$persona->isConfigured()) {
            return response()->json([
                'message' => 'Persona identity verification is not configured on the server.',
            ], 503);
        }

        try {
            $payload = $persona->start($request->user());
        } catch (ConnectionException|RequestException $exception) {
            report($exception);

            return response()->json([
                'message' => 'Unable to start Persona identity verification right now.',
            ], 502);
        }

        return response()->json(array_merge($payload, [
            'user' => $request->user()->fresh()->mobileProfile(),
        ]));
    }

    public function complete(Request $request, PersonaIdentityVerificationService $persona): \Illuminate\Http\JsonResponse
    {
        if (!$persona->isEnabled()) {
            return response()->json([
                'message' => 'Persona identity verification is not enabled for this environment.',
            ], 409);
        }

        if (!$persona->isConfigured()) {
            return response()->json([
                'message' => 'Persona identity verification is not configured on the server.',
            ], 503);
        }

        $validator = Validator::make($request->all(), [
            'inquiry_id' => ['sometimes', 'string', 'starts_with:inq_'],
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        try {
            $payload = $persona->sync(
                $request->user(),
                $request->filled('inquiry_id') ? (string) $request->input('inquiry_id') : null
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        } catch (AccessDeniedHttpException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 403);
        } catch (ConnectionException|RequestException $exception) {
            report($exception);

            return response()->json([
                'message' => 'Unable to sync Persona identity verification right now.',
            ], 502);
        }

        return response()->json(array_merge($payload, [
            'user' => $request->user()->fresh()->mobileProfile(),
        ]));
    }
}
