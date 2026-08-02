<?php

namespace App\Training\Domain\ValueObject;

enum AbonementStatus: int
{
    case Unused = 0;
    case Active = 1;
    case Inactive = 2;
}