<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Services\Customers\CustomerDeletionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CustomersController extends Controller
{
    public function __construct(
        private readonly CustomerDeletionService $customerDeletionService,
    ) {}

    public function index(): Response
    {
        $customers = Customer::query()
            ->regular()
            ->orderByDesc('created_at')
            ->get()
            ->map(static fn (Customer $customer): array => [
                'id' => $customer->id,
                'name' => $customer->name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'address' => $customer->address,
                'status' => $customer->status,
                'notes' => $customer->notes,
                'created_at' => optional($customer->created_at)->toIso8601String(),
            ])
            ->all();

        return Inertia::render('Customers/Index', [
            'customers' => $customers,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = validator($this->normalize($request->all()), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'status' => ['required', 'string', 'max:50'],
        ])->validate();

        Customer::query()->create($validated);

        return redirect()->route('customers.index');
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        if ($customer->is_staff) {
            abort(403);
        }
        $validated = validator($this->normalize($request->all()), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'status' => ['required', 'string', 'max:50'],
        ])->validate();

        $customer->update($validated);

        return redirect()->route('customers.index');
    }

    public function destroy(Customer $customer): RedirectResponse
    {
        if ($customer->is_staff) {
            abort(403);
        }
        $this->customerDeletionService->delete($customer);

        return redirect()->route('customers.index');
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function normalize(array $input): array
    {
        foreach (['email', 'phone', 'address', 'notes'] as $field) {
            if (array_key_exists($field, $input) && $input[$field] === '') {
                $input[$field] = null;
            }
        }

        return $input;
    }
}
