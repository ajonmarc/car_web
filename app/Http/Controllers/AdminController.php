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
    public function apiStoreAnnouncement(Request $request)
    {
        $validator = $this->validateAnnouncementData($request);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            // Create announcement
            $announcement = Annonce::create([
                'user_id' => Auth::id(),
                'title' => $request->title,
                'description' => $request->description,
                'car_model' => $request->car_model,
                'city' => $request->city,
                'color' => $request->color,
                'price' => $request->price,
                'premium' => $request->boolean('premium'),
                'premium_duration' => $request->boolean('premium') ? $request->premium_duration : null,
                'stat' => 'active',
            ]);

            // Handle images
            $imagePaths = $this->handleImageUploads($request, $announcement->id);
            if (!empty($imagePaths)) {
                $announcement->update(['images' => json_encode($imagePaths)]);
            }

            // Handle availability
            $this->handleAvailability($request, $announcement->id);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Announcement created successfully',
                'data' => $announcement->load('annoncedispo')
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to create announcement',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update an existing announcement
     */
    public function apiUpdateAnnouncement(Request $request)
    {
        $validator = $this->validateAnnouncementData($request, true);

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

            // Update announcement
            $announcement->update([
                'title' => $request->title,
                'description' => $request->description,
                'car_model' => $request->car_model,
                'city' => $request->city,
                'color' => $request->color,
                'price' => $request->price,
                'premium' => $request->boolean('premium'),
                'premium_duration' => $request->boolean('premium') ? $request->premium_duration : null,
            ]);

            // Handle images if new ones are uploaded
            if ($request->hasFile('images')) {
                // Delete old images
                $this->deleteAnnouncementImages($announcement);
                
                // Upload new images
                $imagePaths = $this->handleImageUploads($request, $announcement->id);
                if (!empty($imagePaths)) {
                    $announcement->update(['images' => json_encode($imagePaths)]);
                }
            }

            // Update availability
            $this->updateAvailability($request, $announcement->id);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Announcement updated successfully',
                'data' => $announcement->fresh()->load('annoncedispo')
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update announcement',
                'error' => $e->getMessage()
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