<?php
// Survey definitions — source of truth for survey.php (questions, weights, teams).
// Question ids (q1, q2 …) are what gets stored in survey_responses.answers, so
// never renumber an existing question; add new ones with new ids.

const SURVEY_TEAMS = ['Tech', 'Digital', 'Creative', 'Other'];

const SURVEY_TEXT_MAX = 2000;

function survey_definitions(): array {
    return [
        'EHI' => [
            'key'      => 'EHI',
            'title'    => 'Employee Happiness Index',
            'intro'    => 'This survey takes about 5 minutes. It is anonymous: your answers are stored separately from your name and email, and nobody can link them back to you.',
            'dimensions' => [
                ['name' => 'Role Clarity & Ownership', 'weight' => 10, 'questions' => [
                    'q1' => 'I clearly understand what is expected of me at work.',
                    'q2' => 'The ownership / commitment I hold on my team is clear and meaningful to me.',
                    'q3' => "I understand how my work connects to my team's goals.",
                ]],
                ['name' => 'Enablement & Resources', 'weight' => 8, 'questions' => [
                    'q4' => 'I have the tools, software and information I need to do my work well.',
                    'q5' => "When I'm blocked, I can get the support or decision I need quickly.",
                    'q6' => 'Our processes help me do good work rather than slow me down.',
                ]],
                ['name' => 'Growth & Learning', 'weight' => 16, 'questions' => [
                    'q7' => 'Digifyce actively invests in my learning and development.',
                    'q8' => 'The Learning & Development sessions are relevant and useful to my growth.',
                    'q9' => 'I can see a path to grow my career here over the next 1–2 years.',
                ]],
                ['name' => 'Recognition & Reward', 'weight' => 18, 'questions' => [
                    'q10' => 'In the last month, I received recognition or praise for good work.',
                    'q11' => 'Our recognition and incentive programs feel fair and motivating.',
                    'q12' => 'The link between my performance and my incentives is clear to me.',
                ]],
                ['name' => 'Manager & Leadership Trust', 'weight' => 16, 'questions' => [
                    'q13' => 'My manager genuinely cares about me as a person.',
                    'q14' => "Leadership communicates openly and honestly about what's happening at Digifyce.",
                    'q15' => 'Decisions that affect me are made fairly.',
                ]],
                ['name' => 'Belonging & Camaraderie', 'weight' => 12, 'questions' => [
                    'q16' => 'I have strong working relationships with the people on my team.',
                    'q17' => 'The Monthly Play engagements genuinely help me feel connected to colleagues.',
                    'q18' => 'I feel I belong at Digifyce.',
                ]],
                ['name' => 'Purpose & Pride', 'weight' => 12, 'questions' => [
                    'q19' => 'I am proud to tell people I work at Digifyce.',
                    'q20' => 'The mission and work of Digifyce make me feel my job is important.',
                    'q21' => 'I would recommend Digifyce as a great place to work.',
                ]],
                ['name' => 'Wellbeing & Balance', 'weight' => 8, 'questions' => [
                    'q22' => 'The pace of work at Digifyce is sustainable for me.',
                    'q23' => 'The Fitness programs and wellbeing support are valuable to me.',
                    'q24' => 'I can balance my work and personal life well.',
                ]],
            ],
            'enps' => 'On a scale of 0-10, how likely are you to recommend Digifyce as a place to work?',
            'open' => [
                'change'     => 'What is the one thing we could change that would most improve your experience at Digifyce?',
                'keep'       => 'What is Digifyce doing well that we should absolutely keep doing?',
                'frustrates' => 'What is something that frustrates you or slows you down that leadership may not be aware of?',
            ],
        ],
        'RUBRICS' => [
            'key'      => 'RUBRICS',
            'title'    => 'Team Performance Rubrics',
            'intro'    => 'Rate your own team. This takes about 3 minutes and is anonymous: your answers are stored separately from your name and email.',
            'dimensions' => [
                ['name' => 'Contribution / Participation', 'weight' => 20, 'questions' => [
                    'q1' => 'Team members actively contribute ideas during discussions and meetings.',
                    'q2' => 'Team members suggest improvements that help the team achieve its goals.',
                ]],
                ['name' => 'Attitude', 'weight' => 15, 'questions' => [
                    'q3' => 'Team members listen to and consider the ideas of others.',
                    'q4' => 'Team members contribute to maintaining a positive and united team environment.',
                ]],
                ['name' => 'Responsibility', 'weight' => 20, 'questions' => [
                    'q5' => 'Team members complete assigned tasks within agreed timelines.',
                    'q6' => 'Team members can be relied upon to fulfill their commitments.',
                ]],
                ['name' => 'Attendance & Punctuality', 'weight' => 15, 'questions' => [
                    'q7' => 'Team members attend meetings regularly and on time.',
                    'q8' => 'Team members respect deadlines and commitments.',
                ]],
                ['name' => 'Organization', 'weight' => 15, 'questions' => [
                    'q9'  => 'Tasks and responsibilities are distributed clearly within the team.',
                    'q10' => 'Team members collaborate effectively when organizing and completing work.',
                ]],
                ['name' => 'Conflict Resolution', 'weight' => 15, 'questions' => [
                    'q11' => 'Team members handle disagreements respectfully and professionally.',
                    'q12' => 'Team members work towards solutions and consensus during conflicts.',
                ]],
            ],
            'enps' => null,
            'open' => [],
        ],
    ];
}

// Survey cycle: one response per person per survey per calendar month.
function survey_period(): string {
    return date('Y-m');
}
