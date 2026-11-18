<?php
namespace ZoltaGaska\Resources;

interface Validator
{
    /** Return field => list of messages. An empty array means valid input. */
    public function validate(array $data): array;
}
