<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Alert;
use App\Models\TypeAlert;
use App\Models\Marque;
use App\Models\Vehicule;
use App\Models\StationService;
use Validator;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;
use DateTime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\File;
use Illuminate\Http\Response;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;
use App\Services\WasabiService;

class VidangeController extends Controller
{
    protected $wasabiService;

    public function __construct(WasabiService $wasabiService)
    {
        $this->wasabiService = $wasabiService;
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
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

        // Récupérer les établissements triés par ID décroissant
        $alerts = Alert::where('station_service_id', $stationService->id)
		->with('vehicule','vehicule.marque', 'vehicule.chauffeur', 'typeAlert', 'user')
        ->orderBy('id', 'desc')->get();

        // Vérifier si des établissements existent
        if ($alerts->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Aucune alert enregistré pour le moment.',
            ], 404);
        }

        // Transformer les données : remplacer user par chauffeur si gestionnaire_de_flotte_id existe
        $alerts = $alerts->map(function ($alert) {
            if ($alert->vehicule && $alert->vehicule->gestionnaire_de_flotte_id !== null) {
                // Masquer le password du chauffeur dans vehicule.chauffeur
                if ($alert->vehicule->chauffeur) {
                    $alert->vehicule->chauffeur->makeHidden('password');
                }
                // Remplacer user par chauffeur
                $alert->setRelation('chauffeur', $alert->vehicule->chauffeur);
                // Masquer le password du chauffeur
                if ($alert->chauffeur) {
                    $alert->chauffeur->makeHidden('password');
                }
                $alert->unsetRelation('user');
            }
            return $alert;
        });

        // Retourner la liste des établissements
        return response()->json([
            'success' => true,
            'message' => 'Liste des alerts.',
            'alerts' => $alerts->map(function ($alert) {
                return $this->attachAlertVehiculePhotoUrls($alert);
            }),
        ], 200);
    }
    /**
     * Display a listing of the resource.
     */
    public function getLastVidangeByMatricule(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'matricule' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation échouée.',
                'errors' => $validator->errors(),
            ], 422);
        }

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

        $vehicule = Vehicule::where('matricule', $request->matricule)->first();
        if (!$vehicule) {
            return response()->json([
                'success' => false,
                'message' => 'Véhicule non trouvé.'
            ], 401);
        }

        // Récupérer les établissements triés par ID décroissant
        $alerts = Alert::where('station_service_id', $stationService->id)
        ->where('vehicule_id', $vehicule->id)
		->with('vehicule','vehicule.marque', 'vehicule.chauffeur', 'typeAlert', 'user')
        ->orderBy('id', 'desc')->first();
        if (!$alerts) {
            return response()->json([
                'success' => false,
                'message' => 'Aucune vidange trouvée pour ce véhicule.',
                'vidange' => $vehicule,
            ], 404);
        }

        // Transformer les données : remplacer user par chauffeur si gestionnaire_de_flotte_id existe
        if ($alerts->vehicule && $alerts->vehicule->gestionnaire_de_flotte_id !== null) {
            // Masquer le password du chauffeur dans vehicule.chauffeur
            if ($alerts->vehicule->chauffeur) {
                $alerts->vehicule->chauffeur->makeHidden('password');
            }
            // Remplacer user par chauffeur
            $alerts->setRelation('chauffeur', $alerts->vehicule->chauffeur);
            // Masquer le password du chauffeur
            if ($alerts->chauffeur) {
                $alerts->chauffeur->makeHidden('password');
            }
            $alerts->unsetRelation('user');
        }

        return response()->json([
            'success' => true,
            'message' => 'Dernière vidange trouvée.',
            'vidange' => $alerts ? $this->attachAlertVehiculePhotoUrls($alerts) : $this->attachVehiculePhotoUrls($vehicule),
        ], 200);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function store(Request $request)
    {
        // Validation des données d'entrée
        $validator = Validator::make($request->all(), [
            'vehicule_id' => 'required|exists:vehicules,id',
            'date_debut' => 'required|date',
            'date_fin' => 'required|date',
            'kilometrage' => 'nullable',
            'autres' => 'nullable',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation échouée.',
                'errors' => $validator->errors(),
            ], 422);
        }

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

		$vehicule = Vehicule::where(['id' => $request->vehicule_id])->first();

        if (!$vehicule) {
            return response()->json([
                'status' => 'error',
                'message' => 'Vehicule non trouvé.'
            ], 401);
        }

        DB::beginTransaction();
        try {
            // Traitement du champ autres
            $autres = $request->autres;
            if (is_array($autres) || is_object($autres)) {
                $autres = json_encode($autres);
            }

            // Création d'une alert
            $alert = new Alert();
            $alert->vehicule_id = $vehicule->id;
            $alert->type_alert_id = 2;
            $alert->date_debut = $request->date_debut;
            $alert->date_fin = $request->date_fin;
            $alert->kilometrage = $request->kilometrage;
            $alert->autres = $autres;
            $alert->station_service_id = $stationService->id;
            $alert->station_id = $station->id;
            $alert->user_id = $vehicule->user_id;

            $alert->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Alert enregistré avec succès.',
                'alert' => $alert,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => "Une erreur est survenue lors de l'enregistrement de l'alert.",
                'dev' => $e->getMessage(),
            ], 500);
        }
    }

    public function getVehiculeByMatricule(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'matricule' => 'required|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation échouée.',
                    'errors' => $validator->errors(),
                ], 422);
            }

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

            $vehicule = Vehicule::where('matricule', $request->matricule)->first();

            if (!$vehicule) {
                return response()->json([
                    'success' => false,
                    'message' => 'Véhicule non trouvé.',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Véhicule trouvé avec succès.',
                'vehicule' => $this->attachVehiculePhotoUrls($vehicule),
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => "Une erreur est survenue lors de la recherche du véhicule.",
                'dev' => $e->getMessage(),
            ], 500);
        }
    }

    protected function attachVehiculePhotoUrls($vehicule)
    {
        if (!$vehicule || empty($vehicule->photos)) {
            return $vehicule;
        }

        $photos = is_array($vehicule->photos)
            ? $vehicule->photos
            : json_decode($vehicule->photos, true);

        if (!is_array($photos)) {
            return $vehicule;
        }

        $vehicule->photos = array_map(function ($photo) {
            try {
                return $this->wasabiService->temporaryUrl($photo) ?? $photo;
            } catch (\Throwable $e) {
                return $photo;
            }
        }, $photos);

        return $vehicule;
    }

    protected function attachAlertVehiculePhotoUrls($alert)
    {
        if ($alert && $alert->vehicule) {
            $alert->setRelation('vehicule', $this->attachVehiculePhotoUrls($alert->vehicule));
        }

        return $alert;
    }

}
