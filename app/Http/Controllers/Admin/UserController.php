<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

/** Admin-only account management; public registration is disabled by design. */
class UserController extends Controller
{
    public function index(): View
    {
        return view('pages.users.index', [
            'title' => 'کاربران',
            'users' => User::orderBy('name')->get()->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'roles' => $u->getRoleNames()->all(),
                'created_at' => $u->created_at,
            ]),
            'roles' => Role::orderBy('name')->pluck('name'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:users,email',
            'password' => ['required', 'confirmed', Password::defaults()],
            'role' => 'required|string|exists:roles,name',
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
        ]);
        $user->assignRole($data['role']);

        return back()->with('success', 'کاربر جدید ساخته شد.');
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'confirmed', Password::defaults()],
            'role' => 'required|string|exists:roles,name',
        ]);

        if ($this->demotesLastAdmin($user, $data['role'])) {
            return back()->withErrors(['role' => 'باید حداقل یک مدیر فعال باقی بماند.']);
        }

        $user->fill(['name' => $data['name'], 'email' => $data['email']]);
        if (! empty($data['password'])) {
            $user->password = $data['password'];
        }
        $user->save();
        $user->syncRoles([$data['role']]);

        return back()->with('success', 'کاربر به‌روزرسانی شد.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->id === $request->user()->id) {
            return back()->withErrors(['user' => 'حذف حساب خودتان از اینجا ممکن نیست.']);
        }

        if ($user->hasRole('admin') && $this->adminCount() <= 1) {
            return back()->withErrors(['user' => 'باید حداقل یک مدیر فعال باقی بماند.']);
        }

        $user->delete();

        return back()->with('success', 'کاربر حذف شد.');
    }

    private function demotesLastAdmin(User $user, string $newRole): bool
    {
        return $user->hasRole('admin') && $newRole !== 'admin' && $this->adminCount() <= 1;
    }

    private function adminCount(): int
    {
        return User::role('admin')->count();
    }
}
