<?php

/*
 | Default country packs. Everything country-specific lives here (or in a tenant's country_configurations row,
 | which is merged over the pack by App\Domain\People\Countries\CountryConfig) - never in the employee schema.
 |
 | Pack keys
 |   name, currency, timezone, locale, date_format, number_format, phone_code, name_format, working_language
 |   address_format    ordered fields: key, label, required, pattern (regex WITHOUT delimiters), hint, options (value => label)
 |                     line1/line2/city/region/postal_code map to columns; any other key is stored in `extra`
 |   identifier_types  key, label, kind (identity | work_authorization), pattern, classification, mask (last4 | last2 | full),
 |                     checksum (luhn | iban, optional). Patterns match the NORMALIZED number (upper case, no spaces . - /)
 |   bank_schema       key, label, required, pattern, normalize (upper | digits | trim), primary (account identifier used for the
 |                     last-4 hint), checksum (iban), options (value => label)
 |   employment_fields / statutory_fields   placeholders for future payroll and compliance (key, label, type)
 |
 | Tenant overrides merge BY KEY: an override entry replaces the default entry with the same key, new keys are appended and
 | {"key": "...", "disabled": true} removes a default entry.
 */

$line = fn (string $label = 'Address line 1', bool $required = true) => ['key' => 'line1', 'label' => $label, 'required' => $required];
$line2 = fn (string $label = 'Address line 2') => ['key' => 'line2', 'label' => $label, 'required' => false];
$city = fn (string $label = 'City', bool $required = true) => ['key' => 'city', 'label' => $label, 'required' => $required];

$generic = [
    'name' => null,
    'currency' => 'USD', 'timezone' => 'UTC', 'locale' => 'en', 'date_format' => 'YYYY-MM-DD', 'number_format' => '1,234.56',
    'phone_code' => null, 'name_format' => '{first} {middle} {last}', 'working_language' => 'en',
    'address_format' => [
        $line(), $line2(), $city('City', false),
        ['key' => 'region', 'label' => 'State / province / region', 'required' => false],
        ['key' => 'postal_code', 'label' => 'Postal code', 'required' => false, 'pattern' => '^[A-Z0-9][A-Z0-9 \-]{1,10}$'],
    ],
    'identifier_types' => [
        ['key' => 'passport', 'label' => 'Passport number', 'kind' => 'identity', 'pattern' => '^[A-Z0-9]{5,20}$', 'classification' => 'highly_sensitive', 'mask' => 'last4'],
        ['key' => 'national_id', 'label' => 'National ID number', 'kind' => 'identity', 'pattern' => '^[A-Z0-9]{4,30}$', 'classification' => 'highly_sensitive', 'mask' => 'last4'],
        ['key' => 'tax_id', 'label' => 'Tax identification number', 'kind' => 'identity', 'pattern' => '^[A-Z0-9]{4,30}$', 'classification' => 'highly_sensitive', 'mask' => 'last4'],
        ['key' => 'visa', 'label' => 'Visa', 'kind' => 'work_authorization', 'classification' => 'highly_sensitive', 'mask' => 'last4'],
        ['key' => 'work_permit', 'label' => 'Work permit', 'kind' => 'work_authorization', 'classification' => 'highly_sensitive', 'mask' => 'last4'],
        ['key' => 'residence_permit', 'label' => 'Residence permit', 'kind' => 'work_authorization', 'classification' => 'highly_sensitive', 'mask' => 'last4'],
    ],
    'bank_schema' => [
        ['key' => 'account_number', 'label' => 'Account number', 'required' => true, 'pattern' => '^[A-Z0-9]{4,34}$', 'normalize' => 'upper', 'primary' => true],
        ['key' => 'swift_bic', 'label' => 'SWIFT / BIC', 'required' => true, 'pattern' => '^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$', 'normalize' => 'upper'],
        ['key' => 'iban', 'label' => 'IBAN (if available)', 'required' => false, 'pattern' => '^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$', 'normalize' => 'upper', 'checksum' => 'iban'],
    ],
    'employment_fields' => [],
    'statutory_fields' => [],
];

$id = fn (string $key, string $label, ?string $pattern = null, string $mask = 'last4', array $extra = []) => array_filter([
    'key' => $key, 'label' => $label, 'kind' => 'identity', 'pattern' => $pattern, 'classification' => 'highly_sensitive', 'mask' => $mask,
], fn ($v) => $v !== null) + $extra;
$auth = fn (string $key, string $label, ?string $pattern = null) => array_filter([
    'key' => $key, 'label' => $label, 'kind' => 'work_authorization', 'pattern' => $pattern, 'classification' => 'highly_sensitive', 'mask' => 'last4',
], fn ($v) => $v !== null);

return [
    'generic' => $generic,

    'countries' => [
        'IN' => [
            'name' => 'India', 'currency' => 'INR', 'timezone' => 'Asia/Kolkata', 'locale' => 'en_IN', 'date_format' => 'DD/MM/YYYY', 'number_format' => '12,34,567.89',
            'phone_code' => '+91', 'name_format' => '{first} {middle} {last}', 'working_language' => 'en',
            'address_format' => [
                $line('Address line 1'), $line2(), $city('City / town'),
                ['key' => 'region', 'label' => 'State', 'required' => true, 'options' => [
                    'AN' => 'Andaman and Nicobar Islands', 'AP' => 'Andhra Pradesh', 'AR' => 'Arunachal Pradesh', 'AS' => 'Assam', 'BR' => 'Bihar', 'CH' => 'Chandigarh', 'CG' => 'Chhattisgarh',
                    'DH' => 'Dadra and Nagar Haveli and Daman and Diu', 'DL' => 'Delhi', 'GA' => 'Goa', 'GJ' => 'Gujarat', 'HR' => 'Haryana', 'HP' => 'Himachal Pradesh', 'JK' => 'Jammu and Kashmir',
                    'JH' => 'Jharkhand', 'KA' => 'Karnataka', 'KL' => 'Kerala', 'LA' => 'Ladakh', 'LD' => 'Lakshadweep', 'MP' => 'Madhya Pradesh', 'MH' => 'Maharashtra', 'MN' => 'Manipur',
                    'ML' => 'Meghalaya', 'MZ' => 'Mizoram', 'NL' => 'Nagaland', 'OD' => 'Odisha', 'PY' => 'Puducherry', 'PB' => 'Punjab', 'RJ' => 'Rajasthan', 'SK' => 'Sikkim',
                    'TN' => 'Tamil Nadu', 'TS' => 'Telangana', 'TR' => 'Tripura', 'UP' => 'Uttar Pradesh', 'UK' => 'Uttarakhand', 'WB' => 'West Bengal',
                ]],
                ['key' => 'postal_code', 'label' => 'PIN code', 'required' => true, 'pattern' => '^[1-9]\d{5}$', 'hint' => 'PIN code must be 6 digits and cannot start with 0.'],
            ],
            'identifier_types' => [
                $id('pan', 'PAN', '^[A-Z]{5}\d{4}[A-Z]$'),
                $id('aadhaar', 'Aadhaar number', '^[2-9]\d{11}$', 'last4'),
                $id('passport', 'Passport number', '^[A-PR-WY][1-9]\d{6}$'),
                $id('uan', 'Universal Account Number (UAN)', '^\d{12}$'),
                $id('driving_licence', 'Driving licence', '^[A-Z]{2}\d{2}\d{4,11}$'),
            ],
            'bank_schema' => [
                ['key' => 'account_number', 'label' => 'Account number', 'required' => true, 'pattern' => '^\d{9,18}$', 'normalize' => 'digits', 'primary' => true],
                ['key' => 'ifsc', 'label' => 'IFSC', 'required' => true, 'pattern' => '^[A-Z]{4}0[A-Z0-9]{6}$', 'normalize' => 'upper'],
                ['key' => 'branch', 'label' => 'Branch', 'required' => false, 'normalize' => 'trim'],
            ],
            'employment_fields' => [
                ['key' => 'pf_applicable', 'label' => 'Provident fund applicable', 'type' => 'boolean'],
                ['key' => 'esi_applicable', 'label' => 'ESI applicable', 'type' => 'boolean'],
            ],
            'statutory_fields' => [
                ['key' => 'pf_number', 'label' => 'PF account number', 'type' => 'text'],
                ['key' => 'esi_number', 'label' => 'ESI number', 'type' => 'text'],
                ['key' => 'professional_tax_state', 'label' => 'Professional tax state', 'type' => 'text'],
            ],
        ],

        'US' => [
            'name' => 'United States', 'currency' => 'USD', 'timezone' => 'America/New_York', 'locale' => 'en_US', 'date_format' => 'MM/DD/YYYY', 'number_format' => '1,234,567.89',
            'phone_code' => '+1', 'name_format' => '{first} {middle} {last}', 'working_language' => 'en',
            'address_format' => [
                $line('Street address'), $line2('Apartment, suite, unit'), $city('City'),
                ['key' => 'region', 'label' => 'State', 'required' => true, 'options' => [
                    'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas', 'CA' => 'California', 'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware',
                    'DC' => 'District of Columbia', 'FL' => 'Florida', 'GA' => 'Georgia', 'HI' => 'Hawaii', 'ID' => 'Idaho', 'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa',
                    'KS' => 'Kansas', 'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine', 'MD' => 'Maryland', 'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota',
                    'MS' => 'Mississippi', 'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska', 'NV' => 'Nevada', 'NH' => 'New Hampshire', 'NJ' => 'New Jersey', 'NM' => 'New Mexico',
                    'NY' => 'New York', 'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio', 'OK' => 'Oklahoma', 'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'RI' => 'Rhode Island',
                    'SC' => 'South Carolina', 'SD' => 'South Dakota', 'TN' => 'Tennessee', 'TX' => 'Texas', 'UT' => 'Utah', 'VT' => 'Vermont', 'VA' => 'Virginia', 'WA' => 'Washington',
                    'WV' => 'West Virginia', 'WI' => 'Wisconsin', 'WY' => 'Wyoming',
                ]],
                ['key' => 'postal_code', 'label' => 'ZIP code', 'required' => true, 'pattern' => '^\d{5}(-?\d{4})?$', 'hint' => 'ZIP code must be 5 digits, or 5+4 digits.'],
            ],
            'identifier_types' => [
                $id('ssn', 'Social Security number', '^(?!000|666|9)\d{3}(?!00)\d{2}(?!0000)\d{4}$'),
                $id('passport', 'Passport number', '^[A-Z0-9]{9}$'),
                $id('itin', 'Individual Taxpayer Identification Number', '^9\d{2}[7-9]\d\d{4}$'),
                $auth('green_card', 'Permanent resident card', '^[A-Z]{3}\d{10}$'),
                $auth('ead', 'Employment Authorization Document', '^[A-Z]{3}\d{10}$'),
            ],
            'bank_schema' => [
                ['key' => 'routing_number', 'label' => 'ABA routing number', 'required' => true, 'pattern' => '^\d{9}$', 'normalize' => 'digits'],
                ['key' => 'account_number', 'label' => 'Account number', 'required' => true, 'pattern' => '^\d{4,17}$', 'normalize' => 'digits', 'primary' => true],
                ['key' => 'account_type', 'label' => 'Account type', 'required' => false, 'options' => ['checking' => 'Checking', 'savings' => 'Savings'], 'normalize' => 'trim'],
            ],
            'employment_fields' => [['key' => 'flsa_status', 'label' => 'FLSA status', 'type' => 'select', 'options' => ['exempt' => 'Exempt', 'non_exempt' => 'Non-exempt']]],
            'statutory_fields' => [
                ['key' => 'w4_filing_status', 'label' => 'W-4 filing status', 'type' => 'text'],
                ['key' => 'state_tax_id', 'label' => 'State tax ID', 'type' => 'text'],
            ],
        ],

        'GB' => [
            'name' => 'United Kingdom', 'currency' => 'GBP', 'timezone' => 'Europe/London', 'locale' => 'en_GB', 'date_format' => 'DD/MM/YYYY', 'number_format' => '1,234,567.89',
            'phone_code' => '+44', 'name_format' => '{first} {middle} {last}', 'working_language' => 'en',
            'address_format' => [
                $line('Address line 1'), $line2(), $city('Town / city'),
                ['key' => 'region', 'label' => 'County', 'required' => false],
                ['key' => 'postal_code', 'label' => 'Postcode', 'required' => true, 'pattern' => '^[A-Z]{1,2}\d[A-Z\d]? ?\d[A-Z]{2}$', 'hint' => 'Enter a valid UK postcode, for example SW1A 1AA.'],
            ],
            'identifier_types' => [
                $id('ni_number', 'National Insurance number', '^(?!BG|GB|NK|KN|TN|NT|ZZ)[A-CEGHJ-PR-TW-Z][A-CEGHJ-NPR-TW-Z]\d{6}[A-D]$'),
                $id('passport', 'Passport number', '^\d{9}$'),
                $id('utr', 'Unique Taxpayer Reference', '^\d{10}$'),
                $auth('brp', 'Biometric residence permit', '^[A-Z]{2}[A-Z0-9]{7}$'),
            ],
            'bank_schema' => [
                ['key' => 'sort_code', 'label' => 'Sort code', 'required' => true, 'pattern' => '^\d{6}$', 'normalize' => 'digits'],
                ['key' => 'account_number', 'label' => 'Account number', 'required' => true, 'pattern' => '^\d{8}$', 'normalize' => 'digits', 'primary' => true],
                ['key' => 'roll_number', 'label' => 'Building society roll number', 'required' => false, 'normalize' => 'trim'],
            ],
            'employment_fields' => [['key' => 'right_to_work_checked', 'label' => 'Right to work checked', 'type' => 'boolean']],
            'statutory_fields' => [
                ['key' => 'tax_code', 'label' => 'Tax code', 'type' => 'text'],
                ['key' => 'student_loan_plan', 'label' => 'Student loan plan', 'type' => 'text'],
            ],
        ],

        'AE' => [
            'name' => 'United Arab Emirates', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'locale' => 'en_AE', 'date_format' => 'DD/MM/YYYY', 'number_format' => '1,234,567.89',
            'phone_code' => '+971', 'name_format' => '{first} {middle} {last}', 'working_language' => 'en',
            'address_format' => [
                $line('Building / street'), $line2('Area / community'), $city('City'),
                ['key' => 'region', 'label' => 'Emirate', 'required' => true, 'options' => [
                    'AZ' => 'Abu Dhabi', 'DU' => 'Dubai', 'SH' => 'Sharjah', 'AJ' => 'Ajman', 'UQ' => 'Umm Al Quwain', 'RK' => 'Ras Al Khaimah', 'FU' => 'Fujairah',
                ]],
                ['key' => 'postal_code', 'label' => 'PO box', 'required' => false, 'pattern' => '^\d{1,6}$', 'hint' => 'PO box must contain digits only.'],
            ],
            'identifier_types' => [
                $id('emirates_id', 'Emirates ID', '^784\d{12}$'),
                $id('passport', 'Passport number', '^[A-Z0-9]{6,9}$'),
                $auth('residence_visa', 'Residence visa', '^[A-Z0-9]{6,20}$'),
                $auth('labour_card', 'Labour card', '^\d{6,12}$'),
            ],
            'bank_schema' => [
                ['key' => 'iban', 'label' => 'IBAN', 'required' => true, 'pattern' => '^AE\d{21}$', 'normalize' => 'upper', 'primary' => true, 'checksum' => 'iban'],
                ['key' => 'swift_bic', 'label' => 'SWIFT / BIC', 'required' => false, 'pattern' => '^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$', 'normalize' => 'upper'],
            ],
            'employment_fields' => [['key' => 'visa_sponsor', 'label' => 'Visa sponsor', 'type' => 'text']],
            'statutory_fields' => [['key' => 'wps_agent_id', 'label' => 'WPS agent ID', 'type' => 'text']],
        ],

        'SG' => [
            'name' => 'Singapore', 'currency' => 'SGD', 'timezone' => 'Asia/Singapore', 'locale' => 'en_SG', 'date_format' => 'DD/MM/YYYY', 'number_format' => '1,234,567.89',
            'phone_code' => '+65', 'name_format' => '{first} {middle} {last}', 'working_language' => 'en',
            'address_format' => [
                $line('Block and street'), $line2('Unit number'), $city('Building', false),
                ['key' => 'postal_code', 'label' => 'Postal code', 'required' => true, 'pattern' => '^\d{6}$', 'hint' => 'Postal code must be 6 digits.'],
            ],
            'identifier_types' => [
                $id('nric', 'NRIC / FIN', '^[STFGM]\d{7}[A-Z]$'),
                $id('passport', 'Passport number', '^[A-Z0-9]{6,9}$'),
                $auth('employment_pass', 'Employment Pass', '^[A-Z0-9]{6,15}$'),
                $auth('s_pass', 'S Pass', '^[A-Z0-9]{6,15}$'),
            ],
            'bank_schema' => [
                ['key' => 'bank_code', 'label' => 'Bank code', 'required' => true, 'pattern' => '^\d{4}$', 'normalize' => 'digits'],
                ['key' => 'branch_code', 'label' => 'Branch code', 'required' => true, 'pattern' => '^\d{3}$', 'normalize' => 'digits'],
                ['key' => 'account_number', 'label' => 'Account number', 'required' => true, 'pattern' => '^\d{7,11}$', 'normalize' => 'digits', 'primary' => true],
            ],
            'employment_fields' => [],
            'statutory_fields' => [['key' => 'cpf_status', 'label' => 'CPF status', 'type' => 'text']],
        ],

        'DE' => [
            'name' => 'Germany', 'currency' => 'EUR', 'timezone' => 'Europe/Berlin', 'locale' => 'de_DE', 'date_format' => 'DD.MM.YYYY', 'number_format' => '1.234.567,89',
            'phone_code' => '+49', 'name_format' => '{first} {last}', 'working_language' => 'de',
            'address_format' => [
                $line('Straße und Hausnummer'), $line2('Adresszusatz'), $city('Ort'),
                ['key' => 'region', 'label' => 'Bundesland', 'required' => false, 'options' => [
                    'BW' => 'Baden-Württemberg', 'BY' => 'Bayern', 'BE' => 'Berlin', 'BB' => 'Brandenburg', 'HB' => 'Bremen', 'HH' => 'Hamburg', 'HE' => 'Hessen', 'MV' => 'Mecklenburg-Vorpommern',
                    'NI' => 'Niedersachsen', 'NW' => 'Nordrhein-Westfalen', 'RP' => 'Rheinland-Pfalz', 'SL' => 'Saarland', 'SN' => 'Sachsen', 'ST' => 'Sachsen-Anhalt', 'SH' => 'Schleswig-Holstein', 'TH' => 'Thüringen',
                ]],
                ['key' => 'postal_code', 'label' => 'Postleitzahl', 'required' => true, 'pattern' => '^\d{5}$', 'hint' => 'The postal code (PLZ) must be 5 digits.'],
            ],
            'identifier_types' => [
                $id('tax_id', 'Steueridentifikationsnummer', '^\d{11}$'),
                $id('social_security', 'Sozialversicherungsnummer', '^\d{8}[A-Z]\d{3}$'),
                $id('national_id', 'Personalausweis number', '^[A-Z0-9]{9}$'),
                $id('passport', 'Passport number', '^[A-Z0-9]{9}$'),
                $auth('residence_permit', 'Aufenthaltstitel (residence permit)', '^[A-Z0-9]{8,12}$'),
            ],
            'bank_schema' => [
                ['key' => 'iban', 'label' => 'IBAN', 'required' => true, 'pattern' => '^DE\d{20}$', 'normalize' => 'upper', 'primary' => true, 'checksum' => 'iban'],
                ['key' => 'bic', 'label' => 'BIC', 'required' => false, 'pattern' => '^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$', 'normalize' => 'upper'],
            ],
            'employment_fields' => [['key' => 'works_council_member', 'label' => 'Works council member', 'type' => 'boolean']],
            'statutory_fields' => [
                ['key' => 'tax_class', 'label' => 'Steuerklasse', 'type' => 'text'],
                ['key' => 'health_insurer', 'label' => 'Krankenkasse', 'type' => 'text'],
            ],
        ],

        'AU' => [
            'name' => 'Australia', 'currency' => 'AUD', 'timezone' => 'Australia/Sydney', 'locale' => 'en_AU', 'date_format' => 'DD/MM/YYYY', 'number_format' => '1,234,567.89',
            'phone_code' => '+61', 'name_format' => '{first} {middle} {last}', 'working_language' => 'en',
            'address_format' => [
                $line('Street address'), $line2('Unit / level'), $city('Suburb'),
                ['key' => 'region', 'label' => 'State / territory', 'required' => true, 'options' => [
                    'NSW' => 'New South Wales', 'VIC' => 'Victoria', 'QLD' => 'Queensland', 'WA' => 'Western Australia', 'SA' => 'South Australia', 'TAS' => 'Tasmania',
                    'ACT' => 'Australian Capital Territory', 'NT' => 'Northern Territory',
                ]],
                ['key' => 'postal_code', 'label' => 'Postcode', 'required' => true, 'pattern' => '^\d{4}$', 'hint' => 'Postcode must be 4 digits.'],
            ],
            'identifier_types' => [
                $id('tfn', 'Tax File Number', '^\d{8,9}$'),
                $id('passport', 'Passport number', '^[A-Z]{1,2}\d{7}$'),
                $auth('visa_grant_number', 'Visa grant number', '^[A-Z0-9]{8,15}$'),
            ],
            'bank_schema' => [
                ['key' => 'bsb', 'label' => 'BSB', 'required' => true, 'pattern' => '^\d{6}$', 'normalize' => 'digits'],
                ['key' => 'account_number', 'label' => 'Account number', 'required' => true, 'pattern' => '^\d{6,10}$', 'normalize' => 'digits', 'primary' => true],
            ],
            'employment_fields' => [],
            'statutory_fields' => [
                ['key' => 'super_fund_name', 'label' => 'Superannuation fund', 'type' => 'text'],
                ['key' => 'super_member_number', 'label' => 'Super member number', 'type' => 'text'],
            ],
        ],

        'CA' => [
            'name' => 'Canada', 'currency' => 'CAD', 'timezone' => 'America/Toronto', 'locale' => 'en_CA', 'date_format' => 'YYYY-MM-DD', 'number_format' => '1,234,567.89',
            'phone_code' => '+1', 'name_format' => '{first} {middle} {last}', 'working_language' => 'en',
            'address_format' => [
                $line('Street address'), $line2('Unit / suite'), $city('City'),
                ['key' => 'region', 'label' => 'Province / territory', 'required' => true, 'options' => [
                    'AB' => 'Alberta', 'BC' => 'British Columbia', 'MB' => 'Manitoba', 'NB' => 'New Brunswick', 'NL' => 'Newfoundland and Labrador', 'NS' => 'Nova Scotia',
                    'NT' => 'Northwest Territories', 'NU' => 'Nunavut', 'ON' => 'Ontario', 'PE' => 'Prince Edward Island', 'QC' => 'Quebec', 'SK' => 'Saskatchewan', 'YT' => 'Yukon',
                ]],
                ['key' => 'postal_code', 'label' => 'Postal code', 'required' => true, 'pattern' => '^[ABCEGHJ-NPRSTVXY]\d[ABCEGHJ-NPRSTV-Z] ?\d[ABCEGHJ-NPRSTV-Z]\d$', 'hint' => 'Enter a valid Canadian postal code, for example K1A 0B1.'],
            ],
            'identifier_types' => [
                $id('sin', 'Social Insurance Number', '^\d{9}$', 'last4', ['checksum' => 'luhn']),
                $id('passport', 'Passport number', '^[A-Z]{2}\d{6}$'),
            ],
            'bank_schema' => [
                ['key' => 'institution_number', 'label' => 'Institution number', 'required' => true, 'pattern' => '^\d{3}$', 'normalize' => 'digits'],
                ['key' => 'transit_number', 'label' => 'Transit number', 'required' => true, 'pattern' => '^\d{5}$', 'normalize' => 'digits'],
                ['key' => 'account_number', 'label' => 'Account number', 'required' => true, 'pattern' => '^\d{7,12}$', 'normalize' => 'digits', 'primary' => true],
            ],
            'employment_fields' => [],
            'statutory_fields' => [['key' => 'td1_status', 'label' => 'TD1 claim status', 'type' => 'text']],
        ],
    ],
];
