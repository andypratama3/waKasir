<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class TeamController extends Controller
{
    /**
     * GET /api/team
     * List all staff members for this business.
     */
    public function index(Request $request): JsonResponse
    {
        $businessId = $request->user()->business_id;
        if (!$businessId) return response()->json(['error' => 'No business'], 403);

        $members = User::where('business_id', $businessId)
            ->select('id', 'name', 'email', 'role', 'created_at')
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['members' => $members]);
    }

    /**
     * POST /api/team/invite
     * Invite a new staff member. Sends a temporary password.
     */
    public function invite(Request $request): JsonResponse
    {
        $request->validate([
            'name'  => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'role'  => 'required|in:staff,admin',
        ]);

        $businessId = $request->user()->business_id;
        if (!$businessId) return response()->json(['error' => 'No business'], 403);

        // Only owner can invite
        if ($request->user()->role !== 'owner') {
            return response()->json(['error' => 'Hanya pemilik yang dapat mengundang anggota tim.'], 403);
        }

        $tempPassword = Str::random(12);

        $member = User::create([
            'name'        => $request->name,
            'email'       => $request->email,
            'password'    => Hash::make($tempPassword),
            'business_id' => $businessId,
            'role'        => $request->role,
        ]);

        // TODO: Send email with temp password when mail is configured
        // Mail::to($member->email)->send(new TeamInviteMail($member, $tempPassword));

        return response()->json([
            'message'       => "Anggota tim {$member->name} berhasil ditambahkan.",
            'member'        => $member->only('id', 'name', 'email', 'role', 'created_at'),
            'temp_password' => $tempPassword,  // only returned once — save immediately
        ], 201);
    }

    /**
     * PUT /api/team/{id}/role
     * Change a staff member's role.
     */
    public function updateRole(Request $request, string $id): JsonResponse
    {
        $request->validate(['role' => 'required|in:staff,admin']);

        $businessId = $request->user()->business_id;
        if (!$businessId) return response()->json(['error' => 'No business'], 403);
        if ($request->user()->role !== 'owner') {
            return response()->json(['error' => 'Hanya pemilik yang dapat mengubah role.'], 403);
        }

        $member = User::where('id', $id)
            ->where('business_id', $businessId)
            ->whereNot('role', 'owner')   // owner cannot be demoted
            ->first();

        if (!$member) return response()->json(['error' => 'Member not found'], 404);

        $member->update(['role' => $request->role]);

        return response()->json(['message' => 'Role berhasil diperbarui.', 'member' => $member]);
    }

    /**
     * DELETE /api/team/{id}
     * Remove a staff member from the business.
     */
    public function remove(Request $request, string $id): JsonResponse
    {
        $businessId = $request->user()->business_id;
        if (!$businessId) return response()->json(['error' => 'No business'], 403);
        if ($request->user()->role !== 'owner') {
            return response()->json(['error' => 'Hanya pemilik yang dapat menghapus anggota.'], 403);
        }
        if ($request->user()->id === $id) {
            return response()->json(['error' => 'Tidak dapat menghapus akun sendiri.'], 422);
        }

        $member = User::where('id', $id)
            ->where('business_id', $businessId)
            ->whereNot('role', 'owner')
            ->first();

        if (!$member) return response()->json(['error' => 'Member not found'], 404);

        $member->tokens()->delete();
        $member->delete();

        return response()->json(['message' => 'Anggota tim berhasil dihapus.']);
    }
}
