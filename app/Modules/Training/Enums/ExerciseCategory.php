<?php

namespace App\Modules\Training\Enums;

enum ExerciseCategory: string
{
    case Strength = 'strength';
    case Powerlifting = 'powerlifting';
    case OlympicWeightlifting = 'olympic_weightlifting';
    case Strongman = 'strongman';
    case Plyometrics = 'plyometrics';
    case Cardio = 'cardio';
    case Stretching = 'stretching';
}
