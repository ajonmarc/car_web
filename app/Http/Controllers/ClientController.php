<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Annonce;
use App\Models\AnnonceDispos;
use App\Models\Demande;
use App\Models\Cart;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;


class ClientController extends Controller
{
    // Get all available car announcements with pagination and filters
    public function apiClientAnnouncements(Request $request)
    {
        try {
            // First, let's try a simpler approach without relationships
            $query = DB::table('annonces')
                ->leftJoin('users', 'annonces.user_id', '=', 'users.id')
                ->where('annonces.stat', 1)
                ->select(
                    'annonces.*',
                    'users.name as owner_name',
                    'users.email as owner_email'
                );

            // Apply filters
            if ($request->has('city') && $request->city) {
                $query->where('annonces.city', 'like', '%' . $request->city . '%');
            }

            if ($request->has('car_model') && $request->car_model) {
                $query->where('annonces.car_model', 'like', '%' . $request->car_model . '%');
            }

            if ($request->has('color') && $request->color) {
                $query->where('annonces.color', $request->color);
            }

            if ($request->has('min_price') && $request->min_price) {
                $query->where('annonces.price', '>=', $request->min_price);
            }

            if ($request->has('max_price') && $request->max_price) {
                $query->where('annonces.price', '<=', $request->max_price);
            }

            // Sort by premium first, then by created date
            $announcements = $query->orderBy('annonces.premium', 'desc')
                  ->orderBy('annonces.created_at', 'desc')
                  ->paginate(10);

            // Get availability for each announcement
            $formatted = $announcements->getCollection()->map(function ($announcement) {
                // Get availability slots for this announcement
                $availability = DB::table('annoncedispos')
                    ->where('annonce_id', $announcement->id)
                    ->select('id', 'day', 'from', 'to', 'stat')
                    ->get()
                    ->map(function ($dispo) {
                        return [
                            'id' => $dispo->id,
                            'day' => $dispo->day,
                            'from' => $dispo->from,
                            'to' => $dispo->to,
                            'available' => $dispo->stat == 1
                        ];
                    });

                return [
                    'id' => $announcement->id,
                    'title' => $announcement->title,
                    'description' => $announcement->description,
                    'car_model' => $announcement->car_model,
                    'city' => $announcement->city,
                    'color' => $announcement->color,
                    'price' => $announcement->price,
                    'premium' => $announcement->premium,
                    'images' => [
                        $announcement->image1,
                        $announcement->image2,
                        $announcement->image3
                    ],
                    'owner' => [
                        'name' => $announcement->owner_name ?? 'Unknown',
                        'email' => $announcement->owner_email ?? 'Unknown'
                    ],
                    'availability' => $availability,
                    'created_at' => $announcement->created_at
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $formatted,
                'pagination' => [
                    'current_page' => $announcements->currentPage(),
                    'last_page' => $announcements->lastPage(),
                    'per_page' => $announcements->perPage(),
                    'total' => $announcements->total()
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch announcements',
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile()
            ], 500);
        }
    }

    // Book a car
    public function bookCar(Request $request)
    {
        try {
            $request->validate([
                'annoncedispo_id' => 'required|exists:annoncedispos,id',
                'reservation_date' => 'required|date|after_or_equal:today',
                'reservation_day' => 'required|string'
            ]);

            $user = Auth::user();
            
            // Check if the availability slot is still available
            $dispo = DB::table('annoncedispos')->where('id', $request->annoncedispo_id)->first();
            if (!$dispo || $dispo->stat != 1) {
                return response()->json([
                    'success' => false,
                    'message' => 'This time slot is no longer available'
                ], 400);
            }

            // Check if user already has a booking for this slot
            $existingBooking = DB::table('demandes')
                ->where('annoncedispo_id', $request->annoncedispo_id)
                ->where('user_id', $user->id)
                ->where('reservation_date', $request->reservation_date)
                ->where('state', '!=', 'cancelled')
                ->first();

            if ($existingBooking) {
                return response()->json([
                    'success' => false,
                    'message' => 'You already have a booking for this slot'
                ], 400);
            }

            // Create the booking
            $bookingId = DB::table('demandes')->insertGetId([
                'annoncedispo_id' => $request->annoncedispo_id,
                'user_id' => $user->id,
                'reservation_date' => $request->reservation_date,
                'reservation_day' => $request->reservation_day,
                'state' => 'pending',
                'feedbackClient' => 'pending',
                'feedbackArticle' => 'pending',
                'created_at' => now(),
                'updated_at' => now()
            ]);

            // Get booking details for response
            $booking = DB::table('demandes')
                ->join('annoncedispos', 'demandes.annoncedispo_id', '=', 'annoncedispos.id')
                ->join('annonces', 'annoncedispos.annonce_id', '=', 'annonces.id')
                ->where('demandes.id', $bookingId)
                ->select(
                    'demandes.*',
                    'annoncedispos.from',
                    'annoncedispos.to',
                    'annonces.title',
                    'annonces.car_model',
                    'annonces.price'
                )
                ->first();

            return response()->json([
                'success' => true,
                'message' => 'Booking request submitted successfully',
                'data' => [
                    'booking_id' => $booking->id,
                    'reservation_date' => $booking->reservation_date,
                    'reservation_day' => $booking->reservation_day,
                    'state' => $booking->state,
                    'car_details' => [
                        'title' => $booking->title,
                        'car_model' => $booking->car_model,
                        'price' => $booking->price
                    ],
                    'time_slot' => [
                        'from' => $booking->from,
                        'to' => $booking->to
                    ]
                ]
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create booking',
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile()
            ], 500);
        }
    }

    // Get filter options for the frontend
    public function getFilterOptions()
    {
        try {
            $cities = DB::table('annonces')
                ->where('stat', 1)
                ->distinct()
                ->pluck('city')
                ->filter()
                ->values();

            $carModels = DB::table('annonces')
                ->where('stat', 1)
                ->distinct()
                ->pluck('car_model')
                ->filter()
                ->values();

            $colors = DB::table('annonces')
                ->where('stat', 1)
                ->distinct()
                ->pluck('color')
                ->filter()
                ->values();

            $priceRange = DB::table('annonces')
                ->where('stat', 1)
                ->selectRaw('MIN(price) as min_price, MAX(price) as max_price')
                ->first();

            return response()->json([
                'success' => true,
                'data' => [
                    'cities' => $cities,
                    'car_models' => $carModels,
                    'colors' => $colors,
                    'price_range' => [
                        'min' => $priceRange->min_price ?? 0,
                        'max' => $priceRange->max_price ?? 1000
                    ]
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch filter options',
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile()
            ], 500);
        }
    }

    // Get user's bookings
    public function getUserBookings()
    {
        try {
            $user = Auth::user();
            
            $bookings = Demande::with(['annonceDispos.annonce'])
                ->where('user_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->get();

            $formatted = $bookings->map(function ($booking) {
                return [
                    'id' => $booking->id,
                    'reservation_date' => $booking->reservation_date,
                    'reservation_day' => $booking->reservation_day,
                    'state' => $booking->state,
                    'feedback_client' => $booking->feedbackClient,
                    'car_details' => [
                        'title' => $booking->annonceDispos->annonce->title,
                        'car_model' => $booking->annonceDispos->annonce->car_model,
                        'city' => $booking->annonceDispos->annonce->city,
                        'price' => $booking->annonceDispos->annonce->price,
                        'image' => $booking->annonceDispos->annonce->image1
                    ],
                    'time_slot' => [
                        'from' => $booking->annonceDispos->from,
                        'to' => $booking->annonceDispos->to
                    ],
                    'created_at' => $booking->created_at->format('Y-m-d H:i:s')
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $formatted
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch bookings',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // Add to cart
    public function addToCart(Request $request)
    {
        try {
            $request->validate([
                'annonce_id' => 'required|exists:annonces,id'
            ]);

            $user = Auth::user();
            
            // Check if already in cart
            $existingCart = Cart::where('annonce_id', $request->annonce_id)
                ->where('user_id', $user->id)
                ->first();

            if ($existingCart) {
                return response()->json([
                    'success' => false,
                    'message' => 'Item already in cart'
                ], 400);
            }

            $cart = Cart::create([
                'annonce_id' => $request->annonce_id,
                'user_id' => $user->id,
                'code_promo' => $request->code_promo ?? null
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Added to cart successfully',
                'data' => $cart
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to add to cart',
                'error' => $e->getMessage()
            ], 500);
        }
    }


public function myRents(Request $request)
{
    $user = auth()->user();

    $rents = Demande::with(['annoncedispo.annonce'])
        ->where('user_id', $user->id)
        ->get();

    return response()->json($rents);
}

public function cancelRent(Request $request)
{
    $request->validate([
        'id' => 'required|exists:demandes,id',
    ]);

    $demande = Demande::find($request->id);

    // Optional: Check if the rent belongs to the authenticated user
    if ($demande->user_id !== auth()->id()) {
        return response()->json(['message' => 'Unauthorized'], 403);
    }

    if ($demande->state === 'cancelled') {
        return response()->json(['message' => 'Already cancelled'], 400);
    }

    $demande->state = 'cancelled';
    $demande->save();

    return response()->json([
        'message' => 'Reservation cancelled successfully',
        'demande' => $demande,
    ]);
}

}