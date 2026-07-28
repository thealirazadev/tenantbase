<?php

namespace TenantBase\Tenancy\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Operator-side signup. It calls the same provisioning path as the web flow,
 * so a tenant created here is indistinguishable from one created in the UI.
 */
class CreateTenantCommand extends Command
{
    protected $signature = 'tenant:create
        {name : Display name for the workspace}
        {--slug= : Subdomain label, defaults to a slug of the name}
        {--plan= : Plan key from config/plans.php}
        {--owner-email= : Email of an existing user who becomes the owner}';

    protected $description = 'Provision a tenant with its owner membership';

    public function handle(): int
    {
        $name = (string) $this->argument('name');
        $slug = Str::lower(trim((string) ($this->option('slug') ?: Str::slug($name))));
        $plan = (string) ($this->option('plan') ?: config('plans.default'));
        $email = Str::lower(trim((string) $this->option('owner-email')));

        $validator = Validator::make(
            ['name' => $name, 'slug' => $slug, 'plan' => $plan, 'owner_email' => $email],
            [
                'name' => ['required', 'string', 'max:255'],
                'slug' => [
                    'required',
                    'string',
                    'min:'.config('tenantbase.slug.min'),
                    'max:'.config('tenantbase.slug.max'),
                    'regex:'.config('tenantbase.slug.pattern'),
                    Rule::notIn(config('tenantbase.slug.reserved')),
                    'unique:tenants,slug',
                ],
                'plan' => ['required', Rule::in(array_keys(config('plans.plans')))],
                'owner_email' => ['required', 'email'],
            ]
        );

        if ($validator->fails()) {
            return $this->reportFailure($validator->errors()->all());
        }

        $userClass = config('tenantbase.models.user');
        $owner = $userClass::query()->where('email', $email)->first();

        if ($owner === null) {
            return $this->reportFailure(["No user account exists for {$email}."]);
        }

        $tenantClass = config('tenantbase.models.tenant');

        try {
            $tenant = $tenantClass::provision($name, $slug, $plan, $owner);
        } catch (Throwable $e) {
            Log::error('tenant.provisioned', ['slug' => $slug, 'exception' => $e::class]);

            return $this->reportFailure(['Could not provision the workspace. Nothing was saved.']);
        }

        $this->line('Tenant created.');
        $this->line(sprintf('  id:     %d', $tenant->id));
        $this->line(sprintf('  slug:   %s', $tenant->slug));
        $this->line(sprintf('  url:    %s', $tenant->url()));
        $this->line(sprintf('  plan:   %s', $tenant->plan));
        $this->line(sprintf('  owner:  %s (membership created)', $email));

        return self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $messages
     */
    private function reportFailure(array $messages): int
    {
        foreach ($messages as $message) {
            $this->output->getErrorStyle()->writeln("<error>{$message}</error>");
        }

        return self::FAILURE;
    }
}
