<?php
namespace ZoltaGaska\Resources;

use Symfony\Component\Validator\Constraints\Collection;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class SymfonyValidator implements Validator
{
    private ValidatorInterface $validator;
    private Collection $constraints;

    public function __construct(array $fields, ?ValidatorInterface $validator = null)
    {
        $this->validator = $validator ?? Validation::createValidator();
        $this->constraints = new Collection(fields: $fields, allowExtraFields: false, allowMissingFields: false);
    }

    public function validate(array $data): array
    {
        $errors = [];
        foreach ($this->validator->validate($data, $this->constraints) as $violation) {
            $field = trim($violation->getPropertyPath(), '[]') ?: '_';
            $errors[$field][] = $violation->getMessage();
        }
        return $errors;
    }
}
