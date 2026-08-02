<?php

namespace App\Training\Domain\ValueObject;

enum TrainingStatus: int
{
    case New = 0;
    case Finished = 1;
    case Cancelled = 2;
}
