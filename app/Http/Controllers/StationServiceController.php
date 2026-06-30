<?php

namespace App\Http\Controllers;

use App\Models\StationService;
use App\Models\ReferralCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Services\WasabiService;
use Illuminate\Support\Str;

class StationServiceController extends Controller
{
    protected $wasabiService;

    public function __construct(WasabiService $wasabiService)
    {
        $this->wasabiService = $wasabiService;
    }

    // Créer une station de service
    public function store(Request $request)
    {
        try {
            $station = auth('api')->user();

            if (!$station) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Station non authentifiée'
                ], 401);
            }

            // Validation des données
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'adresse' => 'required|string|max:255',
                'mobile' => 'required|string|max:255',
                'longitude' => 'required|numeric',
                'latitude' => 'required|numeric',
                'borne_electrique' => 'required|numeric',
                'logo' => 'nullable|file|mimes:jpeg,png,jpg,gif,svg|max:2048',
				'nuit' => 'sometimes|integer|in:0,1',
				'station_electrique' => 'sometimes|integer|in:0,1'
            ]);

            $logoPath = null;
            if ($request->hasFile('logo')) {
                $logoPath = $this->wasabiService->uploadFile(
                    $request->file('logo'),
                    'stations/logos',
                    'station-logo'
                );
            }

            // Génération et création du code de parrainage
            /*$referralCode = ReferralCode::create([
                'user_id' => $station->id,
                'code' => ReferralCode::generateUniqueCode()
            ]);*/

            $stationServiceData = [
                'name' => $validated['name'],
                'adresse' => $validated['adresse'],
                'mobile' => $validated['mobile'],
                'longitude' => $validated['longitude'],
                'latitude' => $validated['latitude'],
                'borne_electrique' => $validated['borne_electrique'],
                'statut' => 1,
                'logo' => $logoPath,
                'created_by' => $station->id,
            ];

            foreach (['nuit', 'station_electrique'] as $field) {
                if (array_key_exists($field, $validated)) {
                    $stationServiceData[$field] = (int) $validated[$field];
                }
            }

            $stationService = StationService::create($stationServiceData);

            return response()->json([
                'status' => 'success',
                'data' => $this->attachStationServiceMediaUrls($stationService->load('referralCode'))
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }


    // Récupérer toutes les stations de service
    public function index()
    {
		$station = auth('api')->user();

		if (!$station) {
			return response()->json([
				'status' => 'error',
				'message' => 'Station non authentifiée'
			], 401);
		}

        if (empty($station)) {
            return response()->json([
                'message' => 'Utilisateur introuvable',
            ]);
        }

        $stationServices = StationService::where('created_by', $station->id)->get();
        return response()->json($stationServices->map(function ($stationService) {
            return $this->attachStationServiceMediaUrls($stationService);
        }));
    }

    // Récupérer une station de service spécifique
    public function show($id)
    {
        $user = auth('api')->user();

        if (empty($user)) {
            return response()->json([
                'message' => 'Utilisateur introuvable',
            ]);
        }

        $stationService = StationService::findOrFail($id);
        return response()->json($this->attachStationServiceMediaUrls($stationService));
    }

    // Mettre à jour une station de service
    public function update(Request $request, $id)
    {
        try {
            $station = auth('api')->user();

            if (!$station) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Station non authentifiée'
                ], 401);
            }

            // Validation des données
            $validated = $request->validate([
                'name' => 'sometimes|string|max:255',
                'adresse' => 'sometimes|string|max:255',
                'mobile' => 'sometimes|string|max:255',
                'longitude' => 'sometimes|numeric',
                'latitude' => 'sometimes|numeric',
                'borne_electrique' => 'sometimes|numeric',
                'statut' => 'sometimes|integer',
                'logo' => 'nullable|file|mimes:jpeg,png,jpg,gif,svg|max:2048',
				'nuit' => 'sometimes|integer|in:0,1',
				'station_electrique' => 'sometimes|integer|in:0,1'
            ]);

            // Récupérer la station de service
            $stationService = StationService::findOrFail($id);
            $updateData = [];

            foreach ([
                'name',
                'adresse',
                'mobile',
                'longitude',
                'latitude',
                'borne_electrique',
                'statut',
                'nuit',
                'station_electrique',
            ] as $field) {
                if (array_key_exists($field, $validated)) {
                    $updateData[$field] = in_array($field, ['nuit', 'station_electrique'], true)
                        ? (int) $validated[$field]
                        : $validated[$field];
                }
            }

            // Vérifier si un nouveau fichier logo a été téléchargé
            if ($request->hasFile('logo')) {
                if ($stationService->logo) {
                    $this->wasabiService->deleteFile($this->normalizeStationServiceLogoPath($stationService->logo));
                }

                $updateData['logo'] = $this->wasabiService->uploadFile(
                    $request->file('logo'),
                    'stations/logos',
                    'station-logo'
                );
            }

            if (empty($updateData)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Aucune donnée valide à mettre à jour. Si tu envoies un logo ou du multipart/form-data, utilise POST sur cette route plutôt que PUT.'
                ], 422);
            }

            // Mettre à jour la station de service
            $stationService->fill($updateData);
            $stationService->save();
            $stationService->refresh();

            return response()->json([
                'status' => 'success',
                'data' => $this->attachStationServiceMediaUrls($stationService)
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }


    // Supprimer une station de service
    public function destroy($id)
    {
        $stationService = StationService::findOrFail($id);

        if ($stationService->logo) {
            $this->wasabiService->deleteFile($this->normalizeStationServiceLogoPath($stationService->logo));
        }

        $stationService->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Station supprimée avec succès.'
        ], 200);
    }

    // Supprimer le logo d'une station de service
    public function removeLogo($id)
    {
        $stationService = StationService::findOrFail($id);

        if ($stationService->logo) {
            $this->wasabiService->deleteFile($this->normalizeStationServiceLogoPath($stationService->logo));
            $stationService->logo = null;
            $stationService->save();
        }

        return response()->json($this->attachStationServiceMediaUrls($stationService));
    }

    protected function attachStationServiceMediaUrls($stationService)
    {
        if (!$stationService || empty($stationService->logo)) {
            return $stationService;
        }

        $path = $this->normalizeStationServiceLogoPath($stationService->logo);

        try {
            $stationService->logo = $this->wasabiService->temporaryUrl($path) ?? $path;
        } catch (\Throwable $e) {
            $stationService->logo = $path;
        }

        return $stationService;
    }

    protected function normalizeStationServiceLogoPath($value)
    {
        if (empty($value) || filter_var($value, FILTER_VALIDATE_URL)) {
            return $value;
        }

        if (Str::contains($value, '/')) {
            return ltrim($value, '/');
        }

        return 'stations/logos/' . ltrim($value, '/');
    }

}
