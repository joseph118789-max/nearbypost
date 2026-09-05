<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Contribution\ReviewRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Editorial policy, editable by the people who set it.
 *
 * The rules the reviewer applies were written into a PHP prompt, so changing
 * one meant a deployment and reading them meant opening a source file. Policy
 * belongs to the newsroom, not to the codebase.
 */
class RuleController extends Controller
{
    /** Offered as a starting point on an empty list; each can be edited or removed. */
    private const SUGGESTED = [
        ['rule' => 'No personal opinion presented as reporting. A view about an event is not a report of one.', 'applies_to' => 'both'],
        ['rule' => 'No jokes, mockery or sarcasm at the expense of a named person or group.', 'applies_to' => 'both'],
        ['rule' => 'No advertising, promotion or plugging a business, product or service.', 'applies_to' => 'contributor'],
        ['rule' => 'No accusations against a named private individual unless the text says a court or the police are involved.', 'applies_to' => 'both'],
        ['rule' => 'No rumour. If the text cannot say who said it or where it happened, refuse it.', 'applies_to' => 'both'],
        ['rule' => 'No asking readers to contact a phone number, WhatsApp or social account.', 'applies_to' => 'contributor'],
    ];

    public function __construct(private ReviewRules $rules)
    {
    }

    public function index(): View
    {
        $country = \App\Support\BrainCountry::iso2();

        // All: every rule, with its country shown. A country: the base rules and that country's own.
        $rules = DB::table('review_rules')
            ->when($country !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('country')->orWhere('country', $country)))
            ->orderBy('sort_order')->orderBy('id')->get();

        return view('admin.rules', [
            'rules'     => $rules,
            'country'   => $country,
            'countryName' => \App\Support\BrainCountry::name($country),
            'suggested' => self::SUGGESTED,
            'inUse'     => [
                'contributor' => $this->rules->active('contributor', $country),
                'scraper'     => $this->rules->active('scraper', $country),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'rule'       => ['required', 'string', 'min:6', 'max:400'],
            'applies_to' => ['required', 'in:both,contributor,scraper'],
        ]);

        DB::table('review_rules')->insert([
            'rule'       => trim($data['rule']),
            'applies_to' => $data['applies_to'],
            'country'    => \App\Support\BrainCountry::iso2(),   // a rule added while looking at a country is that country's
            'is_active'  => true,
            'sort_order' => (int) DB::table('review_rules')->max('sort_order') + 1,
            'created_by' => Auth::guard('admin')->id(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->rules->bumpVersion();

        return back()->with('status', 'Rule added. It applies to the next submission.');
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate([
            'rule'       => ['required', 'string', 'min:6', 'max:400'],
            'applies_to' => ['required', 'in:both,contributor,scraper'],
        ]);

        DB::table('review_rules')->where('id', $id)->update([
            'rule'       => trim($data['rule']),
            'applies_to' => $data['applies_to'],
            'is_active'  => $request->boolean('is_active'),
            'updated_at' => now(),
        ]);

        $this->rules->bumpVersion();

        return back()->with('status', 'Rule saved.');
    }

    public function destroy(int $id): RedirectResponse
    {
        DB::table('review_rules')->where('id', $id)->delete();

        $this->rules->bumpVersion();

        return back()->with('status', 'Rule removed.');
    }

    /** Put the suggested starting set in, for a newsroom with an empty list. */
    public function seed(): RedirectResponse
    {
        if (DB::table('review_rules')->exists()) {
            return back()->with('status', 'There are already rules; nothing added.');
        }

        $order = 0;

        foreach (self::SUGGESTED as $rule) {
            DB::table('review_rules')->insert([
                'rule'       => $rule['rule'],
                'applies_to' => $rule['applies_to'],
                'is_active'  => true,
                'sort_order' => $order++,
                'created_by' => Auth::guard('admin')->id(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->rules->bumpVersion();

        return back()->with('status', 'Added ' . count(self::SUGGESTED) . ' starting rules. Edit or remove any of them.');
    }
}
