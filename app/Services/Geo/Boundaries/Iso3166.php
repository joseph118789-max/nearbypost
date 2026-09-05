<?php

namespace App\Services\Geo\Boundaries;

/**
 * ISO 3166-1: two letters, three letters, a name.
 *
 * The rest of this site speaks in two-letter codes because Nominatim does;
 * the boundary source speaks in three because the UN does. This is the
 * bridge, and the one place the list lives.
 */
class Iso3166
{
    /** iso2 => [iso3, English name] */
    private const TABLE = [
        'AF' => ['AFG', 'Afghanistan'], 'AL' => ['ALB', 'Albania'], 'DZ' => ['DZA', 'Algeria'],
        'AS' => ['ASM', 'American Samoa'], 'AD' => ['AND', 'Andorra'], 'AO' => ['AGO', 'Angola'],
        'AI' => ['AIA', 'Anguilla'], 'AQ' => ['ATA', 'Antarctica'], 'AG' => ['ATG', 'Antigua and Barbuda'],
        'AR' => ['ARG', 'Argentina'], 'AM' => ['ARM', 'Armenia'], 'AW' => ['ABW', 'Aruba'],
        'AU' => ['AUS', 'Australia'], 'AT' => ['AUT', 'Austria'], 'AZ' => ['AZE', 'Azerbaijan'],
        'BS' => ['BHS', 'Bahamas'], 'BH' => ['BHR', 'Bahrain'], 'BD' => ['BGD', 'Bangladesh'],
        'BB' => ['BRB', 'Barbados'], 'BY' => ['BLR', 'Belarus'], 'BE' => ['BEL', 'Belgium'],
        'BZ' => ['BLZ', 'Belize'], 'BJ' => ['BEN', 'Benin'], 'BM' => ['BMU', 'Bermuda'],
        'BT' => ['BTN', 'Bhutan'], 'BO' => ['BOL', 'Bolivia'], 'BA' => ['BIH', 'Bosnia and Herzegovina'],
        'BW' => ['BWA', 'Botswana'], 'BR' => ['BRA', 'Brazil'], 'BN' => ['BRN', 'Brunei'],
        'BG' => ['BGR', 'Bulgaria'], 'BF' => ['BFA', 'Burkina Faso'], 'BI' => ['BDI', 'Burundi'],
        'KH' => ['KHM', 'Cambodia'], 'CM' => ['CMR', 'Cameroon'], 'CA' => ['CAN', 'Canada'],
        'CV' => ['CPV', 'Cape Verde'], 'KY' => ['CYM', 'Cayman Islands'], 'CF' => ['CAF', 'Central African Republic'],
        'TD' => ['TCD', 'Chad'], 'CL' => ['CHL', 'Chile'], 'CN' => ['CHN', 'China'],
        'CO' => ['COL', 'Colombia'], 'KM' => ['COM', 'Comoros'], 'CG' => ['COG', 'Congo'],
        'CD' => ['COD', 'Democratic Republic of the Congo'], 'CK' => ['COK', 'Cook Islands'], 'CR' => ['CRI', 'Costa Rica'],
        'CI' => ['CIV', "Cote d'Ivoire"], 'HR' => ['HRV', 'Croatia'], 'CU' => ['CUB', 'Cuba'],
        'CW' => ['CUW', 'Curacao'], 'CY' => ['CYP', 'Cyprus'], 'CZ' => ['CZE', 'Czech Republic'],
        'DK' => ['DNK', 'Denmark'], 'DJ' => ['DJI', 'Djibouti'], 'DM' => ['DMA', 'Dominica'],
        'DO' => ['DOM', 'Dominican Republic'], 'EC' => ['ECU', 'Ecuador'], 'EG' => ['EGY', 'Egypt'],
        'SV' => ['SLV', 'El Salvador'], 'GQ' => ['GNQ', 'Equatorial Guinea'], 'ER' => ['ERI', 'Eritrea'],
        'EE' => ['EST', 'Estonia'], 'SZ' => ['SWZ', 'Eswatini'], 'ET' => ['ETH', 'Ethiopia'],
        'FK' => ['FLK', 'Falkland Islands'], 'FO' => ['FRO', 'Faroe Islands'], 'FJ' => ['FJI', 'Fiji'],
        'FI' => ['FIN', 'Finland'], 'FR' => ['FRA', 'France'], 'GF' => ['GUF', 'French Guiana'],
        'PF' => ['PYF', 'French Polynesia'], 'GA' => ['GAB', 'Gabon'], 'GM' => ['GMB', 'Gambia'],
        'GE' => ['GEO', 'Georgia'], 'DE' => ['DEU', 'Germany'], 'GH' => ['GHA', 'Ghana'],
        'GI' => ['GIB', 'Gibraltar'], 'GR' => ['GRC', 'Greece'], 'GL' => ['GRL', 'Greenland'],
        'GD' => ['GRD', 'Grenada'], 'GP' => ['GLP', 'Guadeloupe'], 'GU' => ['GUM', 'Guam'],
        'GT' => ['GTM', 'Guatemala'], 'GG' => ['GGY', 'Guernsey'], 'GN' => ['GIN', 'Guinea'],
        'GW' => ['GNB', 'Guinea-Bissau'], 'GY' => ['GUY', 'Guyana'], 'HT' => ['HTI', 'Haiti'],
        'HN' => ['HND', 'Honduras'], 'HK' => ['HKG', 'Hong Kong'], 'HU' => ['HUN', 'Hungary'],
        'IS' => ['ISL', 'Iceland'], 'IN' => ['IND', 'India'], 'ID' => ['IDN', 'Indonesia'],
        'IR' => ['IRN', 'Iran'], 'IQ' => ['IRQ', 'Iraq'], 'IE' => ['IRL', 'Ireland'],
        'IM' => ['IMN', 'Isle of Man'], 'IL' => ['ISR', 'Israel'], 'IT' => ['ITA', 'Italy'],
        'JM' => ['JAM', 'Jamaica'], 'JP' => ['JPN', 'Japan'], 'JE' => ['JEY', 'Jersey'],
        'JO' => ['JOR', 'Jordan'], 'KZ' => ['KAZ', 'Kazakhstan'], 'KE' => ['KEN', 'Kenya'],
        'KI' => ['KIR', 'Kiribati'], 'KP' => ['PRK', 'North Korea'], 'KR' => ['KOR', 'South Korea'],
        'XK' => ['XKX', 'Kosovo'], 'KW' => ['KWT', 'Kuwait'], 'KG' => ['KGZ', 'Kyrgyzstan'],
        'LA' => ['LAO', 'Laos'], 'LV' => ['LVA', 'Latvia'], 'LB' => ['LBN', 'Lebanon'],
        'LS' => ['LSO', 'Lesotho'], 'LR' => ['LBR', 'Liberia'], 'LY' => ['LBY', 'Libya'],
        'LI' => ['LIE', 'Liechtenstein'], 'LT' => ['LTU', 'Lithuania'], 'LU' => ['LUX', 'Luxembourg'],
        'MO' => ['MAC', 'Macau'], 'MG' => ['MDG', 'Madagascar'], 'MW' => ['MWI', 'Malawi'],
        'MY' => ['MYS', 'Malaysia'], 'MV' => ['MDV', 'Maldives'], 'ML' => ['MLI', 'Mali'],
        'MT' => ['MLT', 'Malta'], 'MH' => ['MHL', 'Marshall Islands'], 'MQ' => ['MTQ', 'Martinique'],
        'MR' => ['MRT', 'Mauritania'], 'MU' => ['MUS', 'Mauritius'], 'YT' => ['MYT', 'Mayotte'],
        'MX' => ['MEX', 'Mexico'], 'FM' => ['FSM', 'Micronesia'], 'MD' => ['MDA', 'Moldova'],
        'MC' => ['MCO', 'Monaco'], 'MN' => ['MNG', 'Mongolia'], 'ME' => ['MNE', 'Montenegro'],
        'MS' => ['MSR', 'Montserrat'], 'MA' => ['MAR', 'Morocco'], 'MZ' => ['MOZ', 'Mozambique'],
        'MM' => ['MMR', 'Myanmar'], 'NA' => ['NAM', 'Namibia'], 'NR' => ['NRU', 'Nauru'],
        'NP' => ['NPL', 'Nepal'], 'NL' => ['NLD', 'Netherlands'], 'NC' => ['NCL', 'New Caledonia'],
        'NZ' => ['NZL', 'New Zealand'], 'NI' => ['NIC', 'Nicaragua'], 'NE' => ['NER', 'Niger'],
        'NG' => ['NGA', 'Nigeria'], 'NU' => ['NIU', 'Niue'], 'MK' => ['MKD', 'North Macedonia'],
        'MP' => ['MNP', 'Northern Mariana Islands'], 'NO' => ['NOR', 'Norway'], 'OM' => ['OMN', 'Oman'],
        'PK' => ['PAK', 'Pakistan'], 'PW' => ['PLW', 'Palau'], 'PS' => ['PSE', 'Palestine'],
        'PA' => ['PAN', 'Panama'], 'PG' => ['PNG', 'Papua New Guinea'], 'PY' => ['PRY', 'Paraguay'],
        'PE' => ['PER', 'Peru'], 'PH' => ['PHL', 'Philippines'], 'PL' => ['POL', 'Poland'],
        'PT' => ['PRT', 'Portugal'], 'PR' => ['PRI', 'Puerto Rico'], 'QA' => ['QAT', 'Qatar'],
        'RE' => ['REU', 'Reunion'], 'RO' => ['ROU', 'Romania'], 'RU' => ['RUS', 'Russia'],
        'RW' => ['RWA', 'Rwanda'], 'KN' => ['KNA', 'Saint Kitts and Nevis'], 'LC' => ['LCA', 'Saint Lucia'],
        'VC' => ['VCT', 'Saint Vincent and the Grenadines'], 'WS' => ['WSM', 'Samoa'], 'SM' => ['SMR', 'San Marino'],
        'ST' => ['STP', 'Sao Tome and Principe'], 'SA' => ['SAU', 'Saudi Arabia'], 'SN' => ['SEN', 'Senegal'],
        'RS' => ['SRB', 'Serbia'], 'SC' => ['SYC', 'Seychelles'], 'SL' => ['SLE', 'Sierra Leone'],
        'SG' => ['SGP', 'Singapore'], 'SX' => ['SXM', 'Sint Maarten'], 'SK' => ['SVK', 'Slovakia'],
        'SI' => ['SVN', 'Slovenia'], 'SB' => ['SLB', 'Solomon Islands'], 'SO' => ['SOM', 'Somalia'],
        'ZA' => ['ZAF', 'South Africa'], 'SS' => ['SSD', 'South Sudan'], 'ES' => ['ESP', 'Spain'],
        'LK' => ['LKA', 'Sri Lanka'], 'SD' => ['SDN', 'Sudan'], 'SR' => ['SUR', 'Suriname'],
        'SE' => ['SWE', 'Sweden'], 'CH' => ['CHE', 'Switzerland'], 'SY' => ['SYR', 'Syria'],
        'TW' => ['TWN', 'Taiwan'], 'TJ' => ['TJK', 'Tajikistan'], 'TZ' => ['TZA', 'Tanzania'],
        'TH' => ['THA', 'Thailand'], 'TL' => ['TLS', 'Timor-Leste'], 'TG' => ['TGO', 'Togo'],
        'TK' => ['TKL', 'Tokelau'], 'TO' => ['TON', 'Tonga'], 'TT' => ['TTO', 'Trinidad and Tobago'],
        'TN' => ['TUN', 'Tunisia'], 'TR' => ['TUR', 'Turkey'], 'TM' => ['TKM', 'Turkmenistan'],
        'TC' => ['TCA', 'Turks and Caicos Islands'], 'TV' => ['TUV', 'Tuvalu'], 'UG' => ['UGA', 'Uganda'],
        'UA' => ['UKR', 'Ukraine'], 'AE' => ['ARE', 'United Arab Emirates'], 'GB' => ['GBR', 'United Kingdom'],
        'US' => ['USA', 'United States'], 'UY' => ['URY', 'Uruguay'], 'UZ' => ['UZB', 'Uzbekistan'],
        'VU' => ['VUT', 'Vanuatu'], 'VA' => ['VAT', 'Vatican City'], 'VE' => ['VEN', 'Venezuela'],
        'VN' => ['VNM', 'Vietnam'], 'VG' => ['VGB', 'British Virgin Islands'], 'VI' => ['VIR', 'U.S. Virgin Islands'],
        'WF' => ['WLF', 'Wallis and Futuna'], 'EH' => ['ESH', 'Western Sahara'], 'YE' => ['YEM', 'Yemen'],
        'ZM' => ['ZMB', 'Zambia'], 'ZW' => ['ZWE', 'Zimbabwe'],
    ];

    public static function iso3(string $iso2): ?string
    {
        return self::TABLE[strtoupper($iso2)][0] ?? null;
    }

    public static function iso2(string $iso3): ?string
    {
        static $rev = null;

        if ($rev === null) {
            $rev = [];
            foreach (self::TABLE as $two => [$three]) {
                $rev[$three] = $two;
            }
        }

        return $rev[strtoupper($iso3)] ?? null;
    }

    public static function name(string $iso3): ?string
    {
        $two = self::iso2($iso3);

        return $two === null ? null : self::TABLE[$two][1];
    }

    /** @return list<string> every ISO3 this table knows */
    public static function all(): array
    {
        return array_map(fn ($row) => $row[0], array_values(self::TABLE));
    }
}
