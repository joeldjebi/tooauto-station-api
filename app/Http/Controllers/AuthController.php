<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\PasswordResetCode;
use App\Models\Station;
use App\Models\StationService;
use App\Services\SmsService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        protected SmsService $smsService
    ) {}

    public function register(Request $request)
    {
        $request->validate([
            'first_name' => 'required|string|max:200',
            'last_name' => 'required|string|max:200',
            'mobile' => 'required|string|max:20|unique:stations',
            'email' => 'nullable|email|max:300|unique:stations',
            'password' => 'required|string|min:6',
        ]);

        $station = Station::create([
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
            'mobile' => $request->mobile,
            'email' => $request->email,
            'role' => 1,
            'password' => bcrypt($request->password),
            'statut' => 1,
        ]);

        return response()->json(['message' => 'Station registered successfully']);
    }

    public function login(Request $request)
    {
        $request->validate([
            'mobile' => 'required|string',
            'password' => 'required|string',
        ]);

        $credentials = $request->only('mobile', 'password');

        if (!$token = auth('api')->attempt($credentials)) {
            return response()->json(['error' => 'Invalid mobile or password'], 401);
        }

        return $this->respondWithToken($token);
    }

    /**
     * Envoyer un code de réinitialisation par SMS.
     */
    public function forgotPassword(Request $request)
    {
        $request->validate([
            'indicatif' => 'required|string|max:10',
            'mobile' => 'required|string|max:30',
        ]);

        $indicatif = $this->normalizeIndicatif($request->indicatif);
        $mobile = trim($request->mobile);
        $station = $this->findStationByPhone($indicatif, $mobile);
        $smsResponse = null;

        if ($station) {
            $code = (string) random_int(100000, 999999);

            PasswordResetCode::where('indicatif', $indicatif)
                ->where('mobile', $mobile)
                ->whereNull('used_at')
                ->update(['used_at' => now()]);

            PasswordResetCode::create([
                'indicatif' => $indicatif,
                'mobile' => $mobile,
                'code' => Hash::make($code),
                'expires_at' => now()->addMinutes(10),
            ]);

            $message = strtoupper("Votre code de reinitialisation TOO AUTO : " . $code);
            $smsResponse = $this->smsService->send($message, $indicatif . $mobile);
        }

        return response()->json([
            'message' => 'Si ce numéro existe, un code de réinitialisation a été envoyé.',
            'station_found' => (bool) $station,
            'sms_response' => $smsResponse,
        ]);
    }

    /**
     * Réinitialiser le mot de passe avec le code reçu par SMS.
     */
    public function resetPassword(Request $request)
    {
        $request->validate([
            'indicatif' => 'required|string|max:10',
            'mobile' => 'required|string|max:30',
            'code' => 'required|string|size:6',
            'password' => 'required|string|min:6|confirmed',
        ]);

        $indicatif = $this->normalizeIndicatif($request->indicatif);
        $mobile = trim($request->mobile);
        $station = $this->findStationByPhone($indicatif, $mobile);

        if (! $station) {
            throw ValidationException::withMessages([
                'mobile' => ['Code invalide ou expiré.'],
            ]);
        }

        $resetCode = PasswordResetCode::where('indicatif', $indicatif)
            ->where('mobile', $mobile)
            ->whereNull('used_at')
            ->latest()
            ->first();

        if (! $resetCode || $resetCode->expires_at->isPast()) {
            throw ValidationException::withMessages([
                'code' => ['Code invalide ou expiré.'],
            ]);
        }

        if ($resetCode->attempts >= 5) {
            $resetCode->used_at = now();
            $resetCode->save();

            throw ValidationException::withMessages([
                'code' => ['Nombre de tentatives dépassé. Veuillez demander un nouveau code.'],
            ]);
        }

        if (! Hash::check($request->code, $resetCode->code)) {
            $resetCode->increment('attempts');

            throw ValidationException::withMessages([
                'code' => ['Code invalide ou expiré.'],
            ]);
        }

        $station->password = bcrypt($request->password);
        $station->save();

        $resetCode->used_at = now();
        $resetCode->save();

        return response()->json([
            'message' => 'Mot de passe réinitialisé avec succès.',
        ]);
    }

    public function me()
    {
        return response()->json(auth('api')->user());
    }

    public function logout()
    {
        auth('api')->logout();
        return response()->json(['message' => 'Successfully logged out']);
    }

    public function update(Request $request)
    {
        $station = auth('api')->user();

        $request->validate([
            'first_name' => 'sometimes|string|max:200',
            'last_name' => 'sometimes|string|max:200',
            'email' => 'sometimes|email|max:300|unique:stations,email,' . $station->id,
        ]);

        $data = $request->only(['first_name', 'last_name', 'mobile', 'email']);

        $station->update($data);

        return response()->json([
            'message' => 'Station updated successfully',
            'user' => $station
        ]);
    }

    public function updatePassword(Request $request)
    {
        try {
            $station = auth('api')->user();

            if (!$station) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Station non authentifiée'
                ], 401);
            }

            $request->validate([
                'current_password' => 'required|string',
                'new_password' => 'required|string|min:6',
                'new_password_confirmation' => 'required|string|same:new_password',
            ]);

            // Vérifier que l'ancien mot de passe est correct
            if (!Hash::check($request->current_password, $station->password)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Le mot de passe actuel est incorrect'
                ], 422);
            }

            // Mettre à jour le mot de passe
            $station->update([
                'password' => bcrypt($request->new_password)
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Mot de passe mis à jour avec succès'
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation échouée',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Erreur lors de la mise à jour du mot de passe', [
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Une erreur est survenue lors de la mise à jour du mot de passe',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    protected function respondWithToken($token)
    {
		$station = Auth::guard('api')->user();

        if (!$station) {
            return response()->json([
                'status' => 'error',
                'message' => 'Station non authentifiée'
            ], 401);
        }

        $stationService = StationService::where(['created_by' => $station->id])
        ->orWhere('created_by', $station->created_by)
        ->first();

        // if (!$stationService) {
        //     return response()->json([
        //         'status' => 'error',
        //         'message' => 'Station service non trouvé.'
        //     ], 401);
        // }
		
        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60,
            'user' => auth('api')->user(),
			'station_service' => $stationService
        ]);
    }
	
	public function registerEmplye(Request $request)
	{
		try {
			
			$station = auth('api')->user();

			if (!$station) {
				return response()->json([
					'status' => 'error',
					'message' => 'Station non authentifiée'
				], 401);
			}

			$stationService = StationService::where(['created_by' => $station->id])
			->orWhere('created_by', $station->created_by)
			->first();
			if (!$stationService) {
				return response()->json([
					'status' => 'error',
					'message' => 'Station service non trouvé.'
				], 401);
			}
			
			$request->validate([
				'first_name' => 'required|string|max:200',
				'last_name' => 'required|string|max:200',
				'mobile' => 'required|string|max:20|unique:stations',
				//'email' => 'required|email|max:300|unique:stations',
			]);

			$rawPassword = strval(random_int(100000, 999999));

			$station = Station::create([
				'first_name' => $request->first_name,
				'last_name' => $request->last_name,
				'mobile' => $request->mobile,
				//'email' => $request->email,
				'role' => 2,
				'password' => bcrypt($rawPassword),
				'statut' => 1,
				'created_by' => $station->id,
				'station_service_id' => $stationService->id
			]);

			// Assure-toi que mobile contient déjà l'indicatif si besoin
			$mobile = '+225'.$request->mobile;
			$password = $rawPassword;

			$message = strtoupper(
				"Votre compte a ete cree avec succes\n" .
				"Voici vos identifiants de connexion :\n" .
				"Numero de telephone : $mobile\n" .
				"Mot de passe : $password\n".
                "Station : $stationService->name\n" 
			);

			$smsResponse = $this->sendSmsMtarget($message, $mobile);

			return response()->json([
				'status' => 'success',
				'message' => 'Compte employé créé avec succès'
			], 201);
		} catch (\Illuminate\Validation\ValidationException $e) {
			return response()->json([
				'status' => 'error',
				'message' => 'Validation échouée',
				'errors' => $e->errors()
			], 422);
		} catch (\Exception $e) {
			Log::error('Erreur lors de l\'enregistrement de la station', [
				'error' => $e->getMessage()
			]);
			return response()->json([
				'status' => 'error',
				'message' => 'Une erreur est survenue lors de l\'enregistrement',
				'error' => $e->getMessage()
			], 500);
		}
	}

	public function indexEmploye()
	{
		try {
			$station = auth('api')->user();

			if (!$station) {
				return response()->json([
					'status' => 'error',
					'message' => 'Station non authentifiée'
				], 401);
			}

			$stationService = StationService::where(['created_by' => $station->id])
			->orWhere('created_by', $station->created_by)
			->first();
			if (!$stationService) {
				return response()->json([
					'status' => 'error',
					'message' => 'Station service non trouvé.'
				], 401);
			}
			
			// Récupérer toutes les stations avec le rôle 2
			$stations = Station::where(['role' => 2, 'station_service_id' => $stationService->id])->get();

			// Vérifier si aucune station trouvée
			if ($stations->isEmpty()) {
				return response()->json([
					'status' => 'success',
					'message' => 'Aucune station trouvée',
					'data' => []
				], 200);
			}

			// Retourner la liste des stations
			return response()->json([
				'status' => 'success',
				'message' => 'Liste des stations récupérée avec succès',
				'data' => $stations
			], 200);

		} catch (\Exception $e) {
			// Gérer les erreurs inattendues
			\Log::error('Erreur lors de la récupération des stations', [
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString()
			]);

			return response()->json([
				'status' => 'error',
				'message' => 'Erreur lors de la récupération des stations',
				'error' => $e->getMessage()
			], 500);
		}
	}

	
        /**
     * Envoie un SMS via l'API MTarget en utilisant curl_init
     * 
     * @param string $message Le message à envoyer
     * @param string $msisdn Le numéro de téléphone du destinataire (format: +2250758754662)
     * @param string $sender L'expéditeur du SMS (par défaut: TOO AUTO)
     * @return string|false La réponse de l'API ou false en cas d'erreur
     */
    function sendSmsMtarget($message, $msisdn, $sender = 'TOO AUTO') 
    {
        return $this->smsService->sendSmsMtarget($message, $msisdn, $sender);
    }

    protected function findStationByPhone(string $indicatif, string $mobile): ?Station
    {
        return Station::whereIn('mobile', $this->mobileVariants($indicatif, $mobile))->first();
    }

    protected function normalizeIndicatif(string $indicatif): string
    {
        return ltrim(trim($indicatif), '+');
    }

    protected function mobileVariants(string $indicatif, string $mobile): array
    {
        $mobile = trim($mobile);
        $mobileWithoutPlus = ltrim($mobile, '+');
        $mobileWithoutIndicatif = $mobileWithoutPlus;

        if (str_starts_with($mobileWithoutPlus, $indicatif)) {
            $mobileWithoutIndicatif = substr($mobileWithoutPlus, strlen($indicatif));
        }

        $mobileWithoutLeadingZero = ltrim($mobileWithoutIndicatif, '0');

        return array_values(array_unique(array_filter([
            $mobile,
            $mobileWithoutPlus,
            $mobileWithoutIndicatif,
            $mobileWithoutLeadingZero,
            '0' . $mobileWithoutLeadingZero,
            $indicatif . $mobileWithoutIndicatif,
            $indicatif . $mobileWithoutLeadingZero,
            '+' . $indicatif . $mobileWithoutIndicatif,
            '+' . $indicatif . $mobileWithoutLeadingZero,
        ])));
    }
	
}







/*$station = auth('api')->user();

        if (!$station) {
            return response()->json([
                'status' => 'error',
                'message' => 'Station non authentifiée'
            ], 401);
        }

        $stationService = StationService::where(['created_by' => $station->id])
        ->orWhere('created_by', $station->created_by)
        ->first();
        if (!$stationService) {
            return response()->json([
                'status' => 'error',
                'message' => 'Station service non trouvé.'
            ], 401);
        }*/
