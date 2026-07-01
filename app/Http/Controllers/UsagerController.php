<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Models\User;
use App\Models\StationService;
use App\Models\Vehicule;
use App\Models\TypeDeVehicule;
use App\Models\Marque;
use App\Models\TypeDeCarburant;
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

class UsagerController extends Controller
{
    protected $wasabiService;

    public function __construct(WasabiService $wasabiService)
    {
        $this->wasabiService = $wasabiService;
    }


	/**
     * Enregistre un nouvel utilisateur avec son véhicule automatiquement
     *
     * Cette fonction combine l'enregistrement d'un utilisateur et la création
     * automatique de son véhicule en une seule opération transactionnelle.
     *
     * @param Request $request Données de l'utilisateur et du véhicule
     * @return \Illuminate\Http\JsonResponse
     */
	public function registerUsager(Request $request)
    {
        // Validation des données d'entrée pour l'utilisateur et le véhicule
        $validator = Validator::make($request->all(), [
            // Données utilisateur
            'indicatif' => 'required|string',
			'nom' => 'required|string',
			'prenoms' => 'required|string',
            'mobile' => 'required|numeric|unique:users',
            'is_whatsapp' => 'required|numeric',

            // Données véhicule
            'matricule' => 'required|string|unique:vehicules',
            'carte_grise' => 'required|string|unique:vehicules',
            'photos' => 'nullable|array|size:4', // Vérifie que 4 fichiers sont fournis (nullable pour test)
            'photos.*' => 'file|image|max:25048', // Chaque fichier doit être une image de max 2 MB
            'type_de_vehicule_id' => 'required|exists:type_de_vehicules,id',
            'marque_id' => 'required|exists:marques,id',
            'type_de_carburant_id' => 'required|exists:type_de_carburants,id',
            'couleur' => 'required|string|max:50',
            'modele' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation échouée.',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Utilisation d'une transaction pour garantir l'intégrité des données
        DB::beginTransaction();
        try {

			$rawPassword = strval(random_int(100000, 999999));

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

            // Création de l'utilisateur
            $user = new User();
            $user->uuid = (string) Str::uuid();
            $user->indicatif = $request->indicatif;
            $user->mobile = $request->mobile;
            $user->nom = $request->nom;
            $user->prenoms = $request->prenoms;
            $user->password = bcrypt($rawPassword); // Hash sécurisé du mot de passe
			$user->is_whatsapp = $request->is_whatsapp;
			$user->station_id = $station->id;
			$user->station_service_id = $stationService->id;

            $user->save();

            // Création automatique du véhicule pour l'utilisateur
            $vehicule = new Vehicule();
            $vehicule->matricule = $request->matricule;
            $vehicule->carte_grise = $request->carte_grise;
            $vehicule->type_de_vehicule_id = $request->type_de_vehicule_id;
            $vehicule->marque_id = $request->marque_id;
            $vehicule->type_de_carburant_id = $request->type_de_carburant_id;
            $vehicule->couleur = $request->couleur;
            $vehicule->modele = $request->modele;
            $vehicule->user_id = $user->id;
            $vehicule->provenance = 'station';
            $vehicule->provenance_by = 2;
            $vehicule->created_by = $station->id;

            // Sauvegarde des photos du véhicule via Wasabi
            if ($request->hasFile('photos')) {
                $photosPaths = [];

                foreach ($request->file('photos') as $photo) {
                    $photosPaths[] = $this->wasabiService->uploadFile(
                        $photo,
                        'vehicules/photos',
                        'vehicule'
                    );
                }

                $vehicule->photos = json_encode($photosPaths);
            }

            $vehicule->save();

            // Commit de la transaction
            DB::commit();

			$mobileWithIndicatif = $request->indicatif . $request->mobile;
			$password = $rawPassword;

			// Construire le message avec les informations du véhicule
			$message = strtoupper(
				"Votre compte a ete cree avec succes\n" .
				"Voici vos identifiants de connexion :\n" .
				"Numero de telephone : $mobileWithIndicatif\n" .
				"Mot de passe : $password\n" .
				"Votre vehicule a ete enregistre :\n" .
				"Matricule : " . $request->matricule . "\n" .
				"Carte grise : " . $request->carte_grise ."\n" .
                "Lien de l'application : https://tooauto.com/link-app"
			);


			// Envoyer le SMS
            $smsResponse = $this->sendMessageConfirmOrder($message, $mobileWithIndicatif);

            $user->setRelation('vehicules', collect([$this->attachVehiculePhotoUrls($vehicule)]));

            return response()->json([
                'success' => true,
                'message' => 'Utilisateur et véhicule enregistrés avec succès.',
                'user' => $user,
                'vehicule' => $this->attachVehiculePhotoUrls($vehicule),
            ], 201); // Utilisation du code HTTP 201 pour "Created"

        } catch (\Exception $e) {
            // Rollback de la transaction en cas d'erreur
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => "Une erreur est survenue lors de l'enregistrement de l'utilisateur et du véhicule.",
                'dev' => $e->getMessage(),
            ], 500);
        }
    }


	public function getUsagerByStation()
	{
		try {
			$station = auth('api')->user();

            if (!$station) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Station non authentifiée'
                ], 401);
            }

			// Récupérer les usagers associés au station
			$usagers = User::where('station_id', $station->id)
                ->with('vehicules')
                ->get();

			// Vérifier s'il y a des usagers
			if ($usagers->isEmpty()) {
				return response()->json([
					'success' => false,
					'message' => "Aucun usager trouvé.",
				], 404);
			}

			return response()->json([
				'success' => true,
				'data' => $usagers->map(function ($user) {
                    return $this->attachUsagerVehiculePhotoUrls($user);
                }),
			], 200);

		} catch (\Exception $e) {
			// Gestion des erreurs
			return response()->json([
				'success' => false,
				'message' => "Une erreur est survenue lors de l'affichage.",
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

    protected function attachUsagerVehiculePhotoUrls($user)
    {
        if (!$user || !$user->relationLoaded('vehicules')) {
            return $user;
        }

        $user->setRelation('vehicules', $user->vehicules->map(function ($vehicule) {
            return $this->attachVehiculePhotoUrls($vehicule);
        }));

        return $user;
    }

	/**
     * Récupère la liste des types de véhicules
     *
     * @return \Illuminate\Http\JsonResponse
     */
	public function getTypeDeVehicules()
	{
		try {
			// Récupérer tous les types de véhicules
			$typesVehicules = TypeDeVehicule::all();

			// Vérifier s'il y a des types de véhicules
			if ($typesVehicules->isEmpty()) {
				return response()->json([
					'success' => false,
					'message' => "Aucun type de véhicule trouvé.",
				], 404);
			}

			return response()->json([
				'success' => true,
				'data' => $typesVehicules,
			], 200);

		} catch (\Exception $e) {
			// Gestion des erreurs
			return response()->json([
				'success' => false,
				'message' => "Une erreur est survenue lors de la récupération des types de véhicules.",
				'dev' => $e->getMessage(),
			], 500);
		}
	}

	/**
     * Récupère la liste des marques
     *
     * @return \Illuminate\Http\JsonResponse
     */
	public function getMarques()
	{
		try {
			// Récupérer toutes les marques
			$marques = Marque::all();

			// Vérifier s'il y a des marques
			if ($marques->isEmpty()) {
				return response()->json([
					'success' => false,
					'message' => "Aucune marque trouvée.",
				], 404);
			}

			return response()->json([
				'success' => true,
				'data' => $marques,
			], 200);

		} catch (\Exception $e) {
			// Gestion des erreurs
			return response()->json([
				'success' => false,
				'message' => "Une erreur est survenue lors de la récupération des marques.",
				'dev' => $e->getMessage(),
			], 500);
		}
	}

	/**
     * Récupère la liste des types de carburants
     *
     * @return \Illuminate\Http\JsonResponse
     */
	public function getTypeDeCarburants()
	{
		try {
			// Récupérer tous les types de carburants
			$typesCarburants = TypeDeCarburant::all();

			// Vérifier s'il y a des types de carburants
			if ($typesCarburants->isEmpty()) {
				return response()->json([
					'success' => false,
					'message' => "Aucun type de carburant trouvé.",
				], 404);
			}

			return response()->json([
				'success' => true,
				'data' => $typesCarburants,
			], 200);

		} catch (\Exception $e) {
			// Gestion des erreurs
			return response()->json([
				'success' => false,
				'message' => "Une erreur est survenue lors de la récupération des types de carburants.",
				'dev' => $e->getMessage(),
			], 500);
		}
	}

    public function sendMessageConfirmOrder($message, $reciever)
    {
        $url = "https://api.smscloud.ci/v1/campaigns/";
        $token = "XeETy7GtbpU7PwMwXk2HOPlZmgqhu9C57v4";

        $data = [
            'sender' => 'QLOWO',
            'content' => $message,
            'dlrUrl' => 'https://myreturnhost.com',
            'recipients' => [$reciever] // Utiliser directement le numéro passé en paramètre
        ];

        $headers = [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'cache-control: no-cache'
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

        $response = curl_exec($ch);

        if ($response === false) {
            // Gérer l'erreur de requête
            $error = curl_error($ch);
            return response()->json([
                'error' => true,
                'message' => 'Erreur cURL : ' . $error
            ], 500);
        }

        // Traitement de la réponse
        $responseData = json_decode($response, true);
        return response()->json([
            'message' => 'Message envoyé avec succès',
            'body' => $responseData
        ], 200);
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
        // URL de l'API MTarget
        $url = 'https://api-public-2.mtarget.fr/messages';

        // Vérifier et ajouter le signe '+' si nécessaire
        if (strpos($msisdn, '+') !== 0) {
            $msisdn = '+' . $msisdn;
        }

        // Paramètres d'authentification et de message
        $postData = http_build_query([
            'username' => 'bwantech',
            'password' => 'x7jyKG0IJRNH',
            'msisdn' => $msisdn,
            'msg' => $message,
            'sender' => $sender
        ]);

        // Initialisation de cURL
        $ch = curl_init();

        // Configuration des options cURL
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,  // Pour récupérer la réponse
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_SSL_VERIFYPEER => false, // Désactiver la vérification SSL pour les tests
            CURLOPT_TIMEOUT => 30, // Timeout de 30 secondes
        ]);

        // Exécution de la requête
        $response = curl_exec($ch);
        // dd($response);

        // Gestion des erreurs
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \Exception("Erreur cURL : " . $error);
        }

        // Récupération du code de statut HTTP
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        // Fermeture de la session cURL
        curl_close($ch);

        // Vérification du code de statut HTTP
        if ($httpCode !== 200) {
            throw new \Exception("Erreur HTTP : " . $httpCode . " - Réponse : " . $response);
        }

        return $response;
    }
}