<?php

namespace App\Filament\Agency\Auth;

use App\Http\Controllers\SignupController;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Filament\Auth\Pages\Register as BaseRegister;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Plan-aware registration. Reads the plan stashed in session by
 * SignupController::selectPlan() and creates a workspace + workspace member
 * row alongside the user — all inside the parent's database transaction
 * (Filament wraps handleRegistration in wrapInDatabaseTransaction).
 *
 * The plan defaults to 'solo' if a user lands directly on /agency/register
 * without going through /signup. The trial clock starts on signup, NOT on
 * email verification (Stripe pattern, confirmed by product owner).
 */
class Register extends BaseRegister
{
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getWorkspaceNameFormComponent(),
                $this->getNameFormComponent(),
                $this->getEmailFormComponent(),
                $this->getPasswordFormComponent(),
                $this->getPasswordConfirmationFormComponent(),
            ]);
    }

    protected function getWorkspaceNameFormComponent(): Component
    {
        return TextInput::make('workspace_name')
            ->label('Brand or company name')
            ->helperText('Shown on receipts and in the panel header. You can change this later.')
            ->required()
            ->maxLength(120)
            ->dehydrated(false); // We pull it from $data manually inside handleRegistration.
    }

    protected function handleRegistration(array $data): Model
    {
        // Plan stashed by SignupController. If someone navigates to
        // /agency/register directly, default to Solo so the registration
        // never silently produces a workspace-less user.
        $plan = session('signup.plan', 'solo');
        if (! in_array($plan, SignupController::ALLOWED_PLANS, true)) {
            $plan = 'solo';
        }

        $workspaceName = $data['workspace_name'] ?? $data['name'];

        // Strip the workspace_name field — it's not a column on users.
        $userData = collect($data)->except(['workspace_name'])->all();

        /** @var User $user */
        $user = $this->getUserModel()::create($userData);

        // Slug must be unique. We append a random suffix on collision rather
        // than looping — keeps the registration path bounded.
        $slug = Str::slug($workspaceName);
        if (Workspace::where('slug', $slug)->exists()) {
            $slug = $slug . '-' . Str::lower(Str::random(6));
        }

        $workspace = Workspace::create([
            'slug' => $slug,
            'name' => $workspaceName,
            'owner_id' => $user->id,
            'type' => $plan === 'agency' ? 'agency' : ($plan === 'studio' ? 'agency' : 'solo'),
            'plan' => $plan,
            'subscription_status' => 'trialing',
            'trial_ends_at' => now()->addDays(14),
        ]);

        WorkspaceMember::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => 'owner',
            'invited_at' => now(),
            'accepted_at' => now(),
        ]);

        $user->forceFill(['current_workspace_id' => $workspace->id])->save();

        // Plan was consumed — clear it so a logged-in user navigating
        // around doesn't carry a stale plan into a future second account.
        session()->forget('signup.plan');

        return $user;
    }
}
