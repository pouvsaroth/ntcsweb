<?php

// Political Party > Branches: the Excel export's headings.
return [
    'branches_title' => 'Party branches',
    'no' => 'No.',
    'code' => 'Code',
    'name' => 'Branch name',
    'level' => 'Level',
    'province' => 'Province',
    'district' => 'District',
    'commune' => 'Commune',
    'village' => 'Village',
    'leader' => 'Leader',
    'phone' => 'Phone',
    'address' => 'Address',
    'opened_on' => 'Opened on',
    'status' => 'Status',
    'remark' => 'Remark',
    'active' => 'Active',
    'closed' => 'Closed',
    'import' => [
        'officeNeeds' => 'An office needs its code and name.',
        'votersNumber' => 'Voters must be a number.',
        'codeTwice' => 'Office code :code is in the file twice.',
        'seatsNumber' => 'Seats must be a number from 1 to 500.',
        'seatsDiffer' => 'This commune has different seats on another row.',
        'notFound' => ':level ":name" was not found (check the spelling, or use its code).',
        'nothing' => 'No rows found. Use the template: Province, District, Commune, then Office code + Office name, or Seats.',
    ],
    'levels' => [
        'province' => 'Province',
        'district' => 'District',
        'commune' => 'Commune',
        'village' => 'Village',
    ],
];
