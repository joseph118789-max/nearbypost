<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Move the prompt's reasoning out of the code, and write down what a foreign
 * model will not know about Malaysia.
 *
 * The playbook text here is copied from the prompt exactly as it stood, so the
 * first run changes nothing about how stories are judged - it only changes who
 * can change it. Anything already saved is left alone: an editor's wording is
 * not overwritten by a re-run of this command.
 *
 * Run: php artisan knowledge:seed
 *      php artisan knowledge:seed --reset   (restore a section to the original)
 */
class SeedKnowledge extends Command
{
    protected $signature = 'knowledge:seed
        {--reset : Overwrite existing text with the original wording}';

    protected $description = 'Seed the playbook and the Malaysia briefing';

    public function handle(): int
    {
        $reset = (bool) $this->option('reset');

        $written = $this->seedPlaybook($reset);
        $terms = $this->seedBriefing($reset);

        $this->info("Playbook sections written: {$written}");
        $this->info("Briefing terms written: {$terms}");

        \Illuminate\Support\Facades\Cache::forever('playbook:version', (string) time());
        \Illuminate\Support\Facades\Cache::forever('briefing:version', (string) time());

        return 0;
    }

    private function seedPlaybook(bool $reset): int
    {
        $written = 0;

        foreach ($this->playbookSections() as $order => $section) {
            $existing = DB::table('playbook_sections')->where('key', $section['key'])->first();

            if ($existing && !$reset) {
                continue;
            }

            if ($existing) {
                // Keep what it said before replacing it, same as an edit made
                // in the panel would.
                DB::table('playbook_revisions')->insert([
                    'playbook_section_id' => $existing->id,
                    'body'                => $existing->body,
                    'note'                => 'Reset to the original wording',
                    'created_at'          => now(),
                ]);

                DB::table('playbook_sections')->where('key', $section['key'])->update([
                    'body'       => $section['body'],
                    'why'        => $section['why'],
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('playbook_sections')->insert([
                    'key'        => $section['key'],
                    'title'      => $section['title'],
                    'body'       => $section['body'],
                    'why'        => $section['why'],
                    'sort_order' => $order,
                    'is_active'  => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $written++;
        }

        return $written;
    }

    private function seedBriefing(bool $reset): int
    {
        $written = 0;

        foreach ($this->briefingTerms() as $term) {
            $exists = DB::table('briefing_terms')
                ->where('term', $term['term'])->where('kind', $term['kind'])->exists();

            if ($exists && !$reset) {
                continue;
            }

            DB::table('briefing_terms')->updateOrInsert(
                ['term' => $term['term'], 'kind' => $term['kind']],
                [
                    'expansion'   => $term['expansion'],
                    'implication' => $term['implication'],
                    'is_active'   => true,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]
            );

            $written++;
        }

        return $written;
    }

    /** @return list<array{key: string, title: string, why: string, body: string}> */
    private function playbookSections(): array
    {
        return [
            [
                'key'   => 'discard',
                'title' => 'What is not news',
                'why'   => 'The first gate. Everything past here costs a geocode, a translation and a place in the feed, so the cheapest refusal is the earliest one.',
                'body'  => <<<'TEXT'
DISCARD (d = 1) if the item is not news: pure opinion or editorial, unconfirmed
rumour, speculation ("might", "could", "possibly"), he-said-she-said with no
resolution, clickbait without substance, or no actual event. Set e when a listed
code applies. Even when d = 1, still fill rel and sub.
TEXT,
            ],
            [
                'key'   => 'relevance',
                'title' => 'Scoring relevance',
                'why'   => 'Without ceilings the model marks everything 0.9 and the category weights stop deciding anything. {{slots}} is filled in per batch and must stay.',
                'body'  => <<<'TEXT'
RELEVANCE
- Include only categories with non-zero relevance. Omit the rest.
- 1.0 is RARE: it needs a specific place, a specific action, and a verifiable
  fact. At most one category may be 1.0.
- At most one further category may be 0.8-0.9. All others 0.6 or below.
- Opinion, speculation or an interview caps everything at 0.6.
- Remaining high-confidence slots in this batch: {{slots}}. If a slot is 0 you
  may not use that level; choose the next one down.
TEXT,
            ],
            [
                'key'   => 'malaysia_angle',
                'title' => 'The Malaysian angle',
                'why'   => "Sport is the part that was most wrong. An earlier rule refused foreign sport unless a Malaysian was in it, and threw away Messi's retirement, Wawrinka's farewell, Alcaraz and Marquez. The test is whether Malaysians follow it, not whether one is playing.",
                'body'  => <<<'TEXT'
MALAYSIA ANGLE (my) - this is about relevance, NOT about location.

my = 1 when a reader in Malaysia has reason to care:
- anything in or about Malaysia, its people, government, companies or economy
- Malaysians abroad: students, workers, tourists, teams, victims, officials
- a foreign event with material Malaysian consequence - trade, the ringgit,
  fuel, palm oil, tourism, aviation, regional security, an outbreak
- regional news involving Malaysia's neighbours where Malaysia is implicated
- a foreign publisher writing about Malaysia

SPORT IS DIFFERENT, and this is where this test used to get it most wrong.

For sport the question is not "is a Malaysian in it" but "do Malaysians follow
it". A Malaysian newspaper carrying a result from abroad is usually carrying it
because its readers want it.

my = 1 for sport Malaysians follow, whoever is playing:
- badminton, any nation, any level. Malaysia is a badminton country and follows
  An Se Young, Akane Yamaguchi, Viktor Axelsen and the rest as closely as its
  own players - and the Malaysian press under-covers them
- Formula 1 and MotoGP, including races with no Malaysian entrant
- major European football: the big leagues, the Champions League, and
  international tournaments. Messi, Ronaldo, an Arsenal result, a transfer at a
  club with a following here
- the Olympics, Asian Games, SEA Games and Commonwealth Games, any nation
- tennis: Grand Slams, the tours, and the leading players whoever they are
- snooker and cue sports, which have a long following here
- marathons, half marathons and road running, both results and - especially -
  races people can enter
- world championships, and the retirement, injury or record of a globally known
  athlete in any of the above

my = 0 for sport with no following here: a foreign country's lower divisions,
college and school sport abroad, county cricket, minor domestic competitions,
and routine squad or contract notes about teams nobody here supports.

my = 0 when there is no Malaysian connection at all: domestic politics of an
unrelated country, foreign crime, foreign local weather, celebrity news with no
Malaysian involvement.

A foreign location does NOT make a story irrelevant. "Malaysians stranded by
Nepal floods" is my = 1 and its place is Kathmandu. "Flooding at the Grand
Canyon" is my = 0. Judge the angle, then report the place truthfully either way.
TEXT,
            ],
            [
                'key'   => 'where',
                'title' => 'Where a story happened',
                'why'   => 'The most valuable paragraph on the site. Before it existed, 171 stories sat on the Kuala Lumpur centroid and not one row in the table had no place: "News near Kuala Lumpur" was showing Kedah remarks, the national budget and a rescue in Nepal.',
                'body'  => <<<'TEXT'
WHERE - the single most important field, and the one most often got wrong.

Give the place the story HAPPENED or is ABOUT. Not the place it was written,
filed or announced from.

A DATELINE IS NOT A LOCATION. Malaysian articles open with the city the
reporter filed from - "KUALA LUMPUR:", "GEORGE TOWN:", "PUTRAJAYA:". That tells
you where the desk is, not where the news is. A minister standing in Kuala
Lumpur announcing a national policy, or talking about an incident in Kedah, is
not a Kuala Lumpur story.

Return null - and this will be the right answer very often - when the story has
no particular place:
- national policy, budgets, laws, ministry announcements that apply countrywide
- a person profile, an interview, a career story
- markets, currencies, commodities, company results
- a product review, test drive, launch write-up or buyer's guide: it is advice
  rather than an event, and being near it helps nobody
- sport, unless a specific venue or town is central to what happened
- anything where a reader would not be better served by being near it

A race a reader could enter is the exception worth naming: a marathon, half
marathon, fun run or cycling event announced for a named town keeps that town,
because someone deciding whether to enter cares that it is near them. A result
from a race abroad does not need one.

Return a place when being near it genuinely matters:
- an incident at a location: a fire, a crash, a raid, a flood, a closure
- something happening to one town, district or neighbourhood
- an event, opening or disruption people would attend or be caught in

Be as specific as the text allows: "Desa ParkCity, Kuala Lumpur" beats "Kuala
Lumpur", and a district beats a state. If the story is about somewhere outside
Malaysia, give that place - Kathmandu, Bangkok - rather than where it was filed.

A SPEAKER'S ADDRESS IS NOT A LOCATION EITHER. When someone comments on a
national matter, the story is not located where they work. An academic in Penang
saying affordable housing is a national problem is a national housing story, not
a Penang story; an industry body in Petaling Jaya calling for a policy rethink is
not a Petaling Jaya story. Locate the event being discussed, and where there is
no event in one place, return null.

The same goes for the office that published the piece. A car review, a buyer's
guide or a product write-up belongs to nowhere, whatever city the reviewer sat
in.

Ask yourself: would a reader standing in this place be more interested than a
reader anywhere else in the country? If not, the answer is null.
TEXT,
            ],
            [
                'key'   => 'reporting',
                'title' => 'GPS, ambiguity and sub-categories',
                'why'   => 'Sub-category ids are a separate numbering system from category ids, and the model mixes them up unless told twice. Scoring subs for only the favourite category leaves the winning category with none.',
                'body'  => <<<'TEXT'
GPS (g = 1) only when you returned a specific named place above - a town,
district, region or street. "Kuala Lumpur" and "KL" qualify. "urban areas",
"some areas" and "city center" without a city do not. g = 0 whenever place is
null.

AMBIGUOUS (a = 1) when two categories are genuinely equally applicable.

SUB-CATEGORIES: keys are the S-prefixed ids below, e.g. "S124". Never put a
category id in "sub" - they are separate numbering systems. Give relevance for
the sub-categories of every category you scored 0.4 or above, not only your
favourite: the winning category is decided by weight afterwards, and if you
supply sub-categories for one category only, the winner may have none.
TEXT,
            ],
        ];
    }

    /**
     * What a model trained elsewhere will not know.
     *
     * Every entry earns its place by changing an answer. Terms that are merely
     * interesting are left out: this is sent with every article the site reads.
     *
     * @return list<array{term: string, kind: string, expansion: string, implication: string}>
     */
    private function briefingTerms(): array
    {
        return [
            ['term' => 'MB', 'kind' => 'title',
             'expansion' => 'Menteri Besar, the head of government of a Malaysian state',
             'implication' => 'A story about the MB of Kedah is a Kedah story whatever city it was filed from.'],

            ['term' => 'Chief Minister', 'kind' => 'title',
             'expansion' => 'the equivalent of a Menteri Besar in Sabah, Sarawak, Penang and Melaka',
             'implication' => 'Same rule: the story belongs to that state, not to the dateline.'],

            ['term' => 'PMX', 'kind' => 'title',
             'expansion' => 'the tenth Prime Minister of Malaysia, Anwar Ibrahim',
             'implication' => 'A prime ministerial announcement is national: no location.'],

            ['term' => 'Putrajaya', 'kind' => 'place',
             'expansion' => 'the federal administrative capital, a separate federal territory from Kuala Lumpur',
             'implication' => 'Used as shorthand for the federal government. "Putrajaya said" means the government said, and is national, not a Putrajaya story.'],

            ['term' => 'Kuala Lumpur', 'kind' => 'place',
             'expansion' => 'the capital and a federal territory, distinct from Selangor which surrounds it',
             'implication' => 'The most common dateline in Malaysian media, and therefore the place most often wrongly given. Suspect it whenever the story is national.'],

            ['term' => 'Federal territories', 'kind' => 'place',
             'expansion' => 'Kuala Lumpur, Putrajaya and Labuan are territories, not states',
             'implication' => 'Do not append a state name to them. "Kuala Lumpur, Kuala Lumpur" is wrong.'],

            ['term' => 'Sabah and Sarawak', 'kind' => 'place',
             'expansion' => 'the two states on Borneo, roughly 1,300km from peninsular Malaysia',
             'implication' => 'Never near anywhere on the peninsula. A Kuching story is not relevant to a Kuala Lumpur reader by distance.'],

            ['term' => 'Bernama', 'kind' => 'media',
             'expansion' => 'the Malaysian national news agency, a wire service',
             'implication' => 'A wire credit is not a location and not a publisher of record.'],

            ['term' => 'Dewan Rakyat', 'kind' => 'body',
             'expansion' => 'the lower house of the Malaysian parliament',
             'implication' => 'Parliamentary business is national: no location, whatever building it happened in.'],

            ['term' => 'KPDN', 'kind' => 'body',
             'expansion' => 'the Ministry of Domestic Trade and Cost of Living',
             'implication' => 'A ministry announcement applies countrywide: no location.'],

            ['term' => 'PDRM', 'kind' => 'body',
             'expansion' => 'the Royal Malaysia Police',
             'implication' => 'A named district police chief tells you the district, which is usually the real location of the incident.'],

            ['term' => 'JPJ', 'kind' => 'body',
             'expansion' => 'the Road Transport Department',
             'implication' => 'National road rules are national; a roadblock or operation in a named district belongs to that district.'],

            ['term' => 'Budi Madani', 'kind' => 'scheme',
             'expansion' => 'the targeted fuel subsidy scheme, claimed at the pump',
             'implication' => 'A nationwide subsidy. No location, and never the petrol stations where it is claimed.'],

            ['term' => 'MyKad', 'kind' => 'scheme',
             'expansion' => 'the Malaysian national identity card',
             'implication' => 'Its appearance usually signals a nationwide scheme rather than a local event.'],

            ['term' => 'Bank Negara', 'kind' => 'body',
             'expansion' => 'Bank Negara Malaysia, the central bank; OPR is its policy interest rate',
             'implication' => 'Monetary policy is national and has no location.'],

            ['term' => 'Bursa Malaysia', 'kind' => 'body',
             'expansion' => 'the Malaysian stock exchange',
             'implication' => 'Market reports have no location and are rarely worth a high relevance score.'],

            ['term' => 'GLC', 'kind' => 'term',
             'expansion' => 'government-linked company',
             'implication' => 'Corporate results are national business news, not news near the head office.'],

            ['term' => 'kampung', 'kind' => 'place',
             'expansion' => 'a village; often part of a place name, as in Kampung Baru',
             'implication' => 'A named kampung is a precise location and should be kept in full rather than reduced to its state.'],

            ['term' => 'Taman', 'kind' => 'place',
             'expansion' => 'a residential neighbourhood or housing estate, as in Taman Tun Dr Ismail',
             'implication' => 'More precise than the city. Keep it: "Taman Melawati, Kuala Lumpur" beats "Kuala Lumpur".'],

            ['term' => 'BAM', 'kind' => 'body',
             'expansion' => 'the Badminton Association of Malaysia',
             'implication' => 'Badminton has a large following here. Do not refuse badminton news for being foreign.'],
        ];
    }
}
