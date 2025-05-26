<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Annonce;
use App\Models\Annoncedispo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;


class AdminController extends Controller
{
    /**
     * Store a new announcement
     */
    public function store(Request $request)
    {
        // Validate the request
        $validator = Validator::make($request->all(), [
            'title' => 'required|min:5|max:191',
            'description' => 'required|max:1000',
            'car_model' => 'required|max:191',
            'city' => 'required|max:191',
            'color' => 'required|max:191',
            'price' => 'required|numeric|min:0',
            'premium' => 'nullable|boolean',
            'premium_duration' => 'nullable|in:7,15',
            'images.*' => 'nullable|image|mimes:jpg,png,jpeg|max:2048',
            'availability.*.selected' => 'required|boolean',
            'availability.*.from' => 'nullable|date_format:H:i',
            'availability.*.to' => 'nullable|date_format:H:i|after:availability.*.from',
        ]);

        if ($validator->fails()) {
            \Log::error('Validation failed', ['errors' => $validator->errors()]);
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            // Handle premium validation
            if (!$request->input('premium') && $request->input('premium_duration')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Premium must be enabled to set premium duration.',
                ], 422);
            }

            // Store images
            $imagePaths = [];
            if ($request->hasFile('images')) {
                foreach ($request->file('images') as $index => $image) {
                    $path = $image->store('carsImages', 'public');
                    $imagePaths[$index] = Storage::url($path);
                }
            }

            // Create announcement
            $annonce = Annonce::create([
                'user_id' => Auth::id(),
                'title' => $request->input('title'),
                'description' => $request->input('description'),
                'car_model' => $request->input('car_model'),
                'city' => $request->input('city'),
                'color' => $request->input('color'),
                'price' => $request->input('price'),
                'stat' => 1,
                'premium' => $request->input('premium', false),
                'premium_duration' => $request->input('premium') ? $request->input('premium_duration', 7) : 0,
                'image1' => $imagePaths[0] ?? null,
                'image2' => $imagePaths[1] ?? null,
                'image3' => $imagePaths[2] ?? null,
            ]);

            // Handle availability
            $availability = $request->input('availability', []);
            foreach ($availability as $day => $data) {
                if ($data['selected']) {
                    Annoncedispo::create([
                        'annonce_id' => $annonce->id,
                        'day' => $day,
                        'from' => $data['from'],
                        'to' => $data['to'],
                        'stat' => 1,
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Announcement created successfully.',
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create announcement: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update an existing announcement
     */
    public function update(Request $request)
    {
        // Validate the request
        $validated = $request->validate([
            'id' => 'required|exists:annonces,id',
            'title' => 'required|min:5|max:191',
            'description' => 'required|max:1000',
            'car_model' => 'required|max:191',
            'city' => 'required|max:191',
            'color' => 'required|max:191',
            'price' => 'required|numeric|min:0',
            'premium' => 'nullable|boolean',
            'premium_duration' => 'nullable|in:7,15',
            'images.*' => 'nullable|image|mimes:jpg,png,jpeg|max:2048',
            'availability.*.selected' => 'required|boolean',
            'availability.*.from' => 'nullable|date_format:H:i',
            'availability.*.to' => 'nullable|date_format:H:i|after:availability.*.from',
        ]);

        try {
            // Find the announcement
            $annonce = Annonce::where('id', $request->input('id'))
                ->where('user_id', Auth::id())->firstOrFail();

            // Handle images
            $imagePaths = [
                $annonce->image1,
                $annonce->image2,
                $annonce->image3,
            ];
            if ($request->hasFile('images')) {
                // Delete old images if new ones are provided
                foreach (array_filter([$annonce->image1, $annonce->image2, $annonce->image3]) as $oldImage) {
                    Storage::disk('public')->delete(str_replace('/storage/', '', $oldImage));
                }
                foreach ($request->file('images') as $index => $image) {
                    $path = $image->store('carsImages', 'public');
                    $imagePaths[$index] = Storage::url($path);
                }
            }

            // Update announcement
            $annonce->update([
                'title' => $request->input('title'),
                'description' => $request->input('description'),
                'car_model' => $request->input('car_model'),
                'city' => $request->input('city'),
                'color' => $request->input('color'),
                'price' => $request->input('price'),
                'premium' => $request->input('premium', false),
                'premium_duration' => $request->input('premium') ? $request->input('premium_duration', 7) : 0,
                'image1' => $imagePaths[0] ?? null,
                'image2' => $imagePaths[1] ?? null,
                'image3' => $imagePaths[2] ?? null,
            ]);

            // Update availability
            Annoncedispo::where('annonce_id', $annonce->id)->delete();
            $availability = $request->input('availability', []);
            foreach ($availability as $day => $data) {
                if ($data['selected']) {
                    Annoncedispo::create([
                        'annonce_id' => $annonce->id,
                        'day' => $day,
                        'from' => $data['from'],
                        'to' => $data['to'],
                        'stat' => 1,
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Announcement updated successfully.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update announcement: ' . $e->getMessage(),
            ], 500);
        }
    }
    /**
     * Get all announcements for the authenticated user
     */
    public function apiGetAnnouncements()
    {
        try {
            $announcements = Annonce::where('user_id', Auth::id())
                ->with('annoncedispo')
                ->latest()
                ->get()
                ->map(function ($announcement) {
                    // Transform availability data for frontend
                    $availability = $this->formatAvailabilityForFrontend($announcement->annoncedispo);

                    // Parse images JSON
                    $images = $announcement->images ? json_decode($announcement->images, true) : [];

                    return [
                        'id' => $announcement->id,
                        'title' => $announcement->title,
                        'description' => $announcement->description,
                        'car_model' => $announcement->car_model,
                        'city' => $announcement->city,
                        'color' => $announcement->color,
                        'price' => $announcement->price,
                        'premium' => $announcement->premium,
                        'premium_duration' => $announcement->premium_duration,
                        'availability' => $availability,
                        'images' => $images,
                        'created_at' => $announcement->created_at,
                        'updated_at' => $announcement->updated_at,
                    ];
                });

            return response()->json($announcements);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch announcements',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete an announcement
     */
    public function apiDeleteAnnouncement(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|exists:annonces,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $announcement = Annonce::where('id', $request->id)
                ->where('user_id', Auth::id())
                ->firstOrFail();

            // Delete images from storage
            $this->deleteAnnouncementImages($announcement);

            // Delete associated availability
            Annoncedispo::where('annonce_id', $announcement->id)->delete();

            // Delete announcement
            $announcement->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Announcement deleted successfully'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete announcement',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Validate announcement data
     */
    private function validateAnnouncementData(Request $request, $isUpdate = false)
    {
        $rules = [
            'title' => 'required|string|max:255',
            'description' => 'required|string|max:1000',
            'car_model' => 'required|string|max:255',
            'city' => 'required|string|in:Tetouan,Tanger,Houceima,Chefchaouen,Larache,Ouazzane',
            'color' => 'required|string|max:7', // Hex color
            'price' => 'required|numeric|min:0|max:999999.99',
            'premium' => 'required|boolean',
            'premium_duration' => 'required_if:premium,1|in:7,15',
            'images.*' => 'image|mimes:jpeg,png,jpg|max:5120', // 5MB max per image
            'availability' => 'array',
            'availability.*.selected' => 'boolean',
            'availability.*.from' => 'required_if:availability.*.selected,1|date_format:H:i',
            'availability.*.to' => 'required_if:availability.*.selected,1|date_format:H:i|after:availability.*.from',
        ];

        if ($isUpdate) {
            $rules['id'] = 'required|exists:annonces,id';
        }

        return Validator::make($request->all(), $rules);
    }

    /**
     * Handle image uploads
     */
    private function handleImageUploads(Request $request, $announcementId)
    {
        $imagePaths = [];

        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $index => $image) {
                $filename = 'announcement_' . $announcementId . '_' . $index . '_' . time() . '.' . $image->getClientOriginalExtension();
                $path = $image->storeAs('announcements', $filename, 'public');
                $imagePaths[] = $path;
            }
        }

        return $imagePaths;
    }

    /**
     * Handle availability data
     */
    private function handleAvailability(Request $request, $announcementId)
    {
        if ($request->has('availability')) {
            foreach ($request->availability as $day => $data) {
                if (isset($data['selected']) && $data['selected']) {
                    Annoncedispo::create([
                        'annonce_id' => $announcementId,
                        'day' => $day,
                        'from' => $data['from'],
                        'to' => $data['to'],
                        'stat' => 'active',
                    ]);
                }
            }
        }
    }

    /**
     * Update availability data
     */
    private function updateAvailability(Request $request, $announcementId)
    {
        // Delete existing availability
        Annoncedispo::where('annonce_id', $announcementId)->delete();

        // Add new availability
        $this->handleAvailability($request, $announcementId);
    }

    /**
     * Format availability data for frontend
     */
    private function formatAvailabilityForFrontend($annoncedispo)
    {
        $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
        $availability = [];

        foreach ($days as $day) {
            $dayData = $annoncedispo->where('day', $day)->first();
            $availability[$day] = [
                'selected' => $dayData ? true : false,
                'from' => $dayData ? $dayData->from : '',
                'to' => $dayData ? $dayData->to : '',
            ];
        }

        return $availability;
    }

    /**
     * Delete announcement images from storage
     */
    private function deleteAnnouncementImages($announcement)
    {
        if ($announcement->images) {
            $imagePaths = json_decode($announcement->images, true);
            foreach ($imagePaths as $path) {
                Storage::disk('public')->delete($path);
            }
        }
    }
}
