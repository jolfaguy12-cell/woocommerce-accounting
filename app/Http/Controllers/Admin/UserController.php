<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Accounting\Models\Party;
use App\Domain\Accounting\Support\PartyRoleType;
use App\Domain\Accounting\Support\PhoneNormalizer;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * Admin-only account management; public registration is disabled by design.
 *
 * Two different questions live on this screen, and keeping them apart is the
 * whole point:
 *
 *   «سطح دسترسی سیستم» — the Spatie role. What this LOGIN may do in the software.
 *   «نقش‌های تجاری»    — the Party roles. Who this PERSON is to the business.
 *
 * They are not the same thing and must never be inferred from each other: an
 * `accountant` login is not automatically an employee of ours, and a partner who
 * never signs in still needs a salary-free current account. So the user form
 * asks both, separately, and links them through `users.party_id` when — and only
 * when — they happen to describe one person.
 */
class UserController extends Controller
{
    /** The business roles a login can plausibly carry. Customer/supplier are not granted from here. */
    private const BUSINESS_ROLES = [PartyRoleType::Employee, PartyRoleType::Partner];

    public function index(): View
    {
        return view('pages.users.index', [
            'title' => 'کاربران',
            'users' => User::with('party.roles')->orderBy('name')->get()->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'roles' => $u->getRoleNames()->all(),
                'telegram_id' => $u->telegram_id,
                'created_at' => $u->created_at,
                'party_id' => $u->party_id,
                'party_name' => $u->party?->name,
                'party_url' => $u->party ? route('parties.show', $u->party) : null,
                'business_roles' => $u->party
                    ? $u->party->activeRoles()->map(fn ($r) => PartyRoleType::coerce($r->role)->label())->values()->all()
                    : [],
            ]),
            'roles' => Role::orderBy('name')->pluck('name'),
            'businessRoles' => collect(self::BUSINESS_ROLES)
                ->mapWithKeys(fn (PartyRoleType $r) => [$r->value => $r->label()])->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        DB::transaction(function () use ($data, $request) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);
            $user->assignRole($data['role']);

            $this->linkParty($user, $data, $request);
        });

        return back()->with('success', 'کاربر جدید ساخته شد.');
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $this->validated($request, $user);

        if ($this->demotesLastAdmin($user, $data['role'])) {
            return back()->withErrors(['role' => 'باید حداقل یک مدیر فعال باقی بماند.']);
        }

        DB::transaction(function () use ($user, $data, $request) {
            $user->fill([
                'name' => $data['name'],
                'email' => $data['email'],
                'telegram_id' => $data['telegram_id'] ?? null,
            ]);

            if (! empty($data['password'])) {
                $user->password = $data['password'];
            }

            $user->save();
            $user->syncRoles([$data['role']]);

            $this->linkParty($user, $data, $request);
        });

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

        // The Party is NOT deleted with the login. It is a business identity with
        // journal history behind it; deleting a login does not un-hire a person.
        $user->delete();

        return back()->with('success', 'کاربر حذف شد. پرونده طرف حساب او دست‌نخورده باقی ماند.');
    }

    /* ---------------------------------------------------------------------- */

    private function validated(Request $request, ?User $user = null): array
    {
        // A request that says nothing about a business identity is not asking to
        // CHANGE one — so it keeps whatever link the user already has. Defaulting a
        // silent request to 'none' would have quietly unlinked an employee from
        // their own party (and their salary) on any edit that only touched a
        // password.
        $request->mergeIfMissing($user?->party_id
            ? ['party_mode' => 'existing', 'party_id' => $user->party_id]
            : ['party_mode' => 'none']);

        return $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($user?->id)],
            'password' => [$user ? 'nullable' : 'required', 'confirmed', Password::defaults()],
            'role' => 'required|string|exists:roles,name',
            // Chat id used by SendTelegramAlertJob/TelegramNotifier.
            'telegram_id' => 'nullable|string|max:64',

            // «نقش‌های تجاری» — the Party half of the form.
            'party_mode' => ['required', Rule::in(['none', 'existing', 'new'])],
            'party_id' => ['nullable', 'required_if:party_mode,existing', Rule::exists('parties', 'id')],
            'party_name' => 'nullable|required_if:party_mode,new|string|max:255',
            'party_phone' => 'nullable|string|max:32',
            'party_national_id' => 'nullable|string|max:20',
            'business_roles' => 'nullable|array',
            'business_roles.*' => [Rule::in(array_column(self::BUSINESS_ROLES, 'value'))],
        ], [
            'party_id.required_if' => 'طرف حساب را انتخاب کنید.',
            'party_name.required_if' => 'نام طرف حساب جدید الزامی است.',
        ]);
    }

    /**
     * Attach the login to a business identity — an existing one, or a new one.
     *
     * A business role cannot be granted without a Party: activating «کارمند» is
     * what creates the employee profile the salary attaches to, and a role with no
     * party to hold it would be a label with no ledger behind it.
     */
    private function linkParty(User $user, array $data, Request $request): void
    {
        $roles = $data['business_roles'] ?? [];

        if ($data['party_mode'] === 'none') {
            if ($roles !== []) {
                throw ValidationException::withMessages([
                    'party_mode' => 'برای اختصاص نقش تجاری، باید کاربر به یک طرف حساب متصل شود.',
                ]);
            }

            $user->forceFill(['party_id' => null])->save();

            return;
        }

        $party = $data['party_mode'] === 'existing'
            ? Party::findOrFail($data['party_id'])->canonical()
            : $this->createParty($data);

        $user->forceFill(['party_id' => $party->id])->save();

        // Selecting «کارمند» or «شریک» activates the role AND creates its profile —
        // activateRole() does both atomically, so a user can never end up holding a
        // role whose profile row does not exist.
        foreach ($roles as $role) {
            $party->activateRole(PartyRoleType::coerce($role), $request->user()->id);
        }
    }

    /**
     * A new Party, but never a silent duplicate.
     *
     * This form is the easiest place in the whole system to create a second copy
     * of a person who already exists — you are typing a name and a phone number
     * from scratch, for somebody the business already deals with. So before it
     * creates anything, it looks for them: same phone, same email, same national
     * id. If it finds one, it refuses and says who, and the admin picks
     * «طرف حساب موجود» instead. Matching is on identifiers only — never on the
     * name, which is evidence, not identity.
     */
    private function createParty(array $data): Party
    {
        $phone = PhoneNormalizer::normalize($data['party_phone'] ?? null);

        $existing = Party::query()
            ->notMerged()
            ->where(function ($q) use ($phone, $data) {
                $q->whereRaw('1 = 0'); // no identifier given → no match, not "everything"

                if ($phone) {
                    $q->orWhere('normalized_phone', $phone);
                }
                if (filled($data['party_national_id'] ?? null)) {
                    $q->orWhere('national_id', $data['party_national_id']);
                }
                if (filled($data['email'] ?? null)) {
                    $q->orWhere('email', $data['email']);
                }
            })
            ->first();

        if ($existing) {
            throw ValidationException::withMessages([
                'party_name' => "طرف حسابی با همین مشخصات از قبل وجود دارد: «{$existing->name}» (شناسه {$existing->id}). به‌جای ساختن مورد جدید، همان را انتخاب کنید.",
            ]);
        }

        return Party::create([
            'name' => $data['party_name'],
            'party_kind' => 'person',
            'phone' => $data['party_phone'] ?? null,
            'email' => $data['email'] ?? null,
            'national_id' => $data['party_national_id'] ?? null,
        ]);
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
