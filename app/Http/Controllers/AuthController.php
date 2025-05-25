<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Register a new user
     */
 public function register(Request $request): JsonResponse
{
    try {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'country' => 'required|string|max:255',
            'city' => 'required|string|max:255',
            'job' => 'required|string|max:255',
            'description' => 'nullable|string',
            'role' => 'required|in:1,2',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'country' => $request->country,
            'city' => $request->city,
            'job' => $request->job,
            'description' => $request->description,
            'role' => $request->role,
            'password' => Hash::make($request->password),
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Registration successful',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'country' => $user->country,
                    'city' => $user->city,
                    'job' => $user->job,
                    'description' => $user->description,
                    'role' => $user->role,
                    'role_name' => $user->role == 1 ? 'Partner' : 'Client',
                ],
                'token' => $token,
                'token_type' => 'Bearer',
            ],
        ]);
    } catch (\Exception $e) {
        \Log::error('Registration error: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Registration failed. Please try again.',
            'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
        ], 500);
    }
}

    /**
     * Login user
     */
    public function login(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'email' => 'required|email',
                'password' => 'required|string|min:6',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Please provide valid email and password',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Check credentials
            if (!Auth::attempt($request->only('email', 'password'))) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid email or password'
                ], 401);
            }

            $user = Auth::user();
            
            // Revoke all existing tokens
            $user->tokens()->delete();
            
            // Create new token
            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => 'Login successful',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'country' => $user->country,
                        'city' => $user->city,
                        'job' => $user->job,
                        'description' => $user->description,
                        'role' => $user->role,
                        'role_name' => $user->role == 1 ? 'Partner' : 'Client',
                    ],
                    'token' => $token,
                    'token_type' => 'Bearer'
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Login error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Login failed. Please try again.',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    public function logout(Request $request): JsonResponse
{
    try {
        // Revoke the current user's token
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully',
        ]);
    } catch (\Exception $e) {
        \Log::error('Logout error: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Logout failed. Please try again.',
            'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
        ], 500);
    }
    
}

public function me()
{
    try {
        // Get the authenticated user
        $user = auth()->user();
        
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not authenticated'
            ], 401);
        }
        
        // Return user information including the name
        return response()->json([
            'success' => true,
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                // Add other fields you want to expose
                'created_at' => $user->created_at,
                'updated_at' => $user->updated_at,
            ]
        ], 200);
        
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Error retrieving user information',
            'error' => $e->getMessage()
        ], 500);
    }
}

}
