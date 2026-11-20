<?php
namespace Garage;

use PDO;
use ZoltaGaska\Http\HttpException;
use ZoltaGaska\Security\Session;

final class Enquiries
{
    public function __construct(private PDO $db) {}

    public function confirm(array $product, array $input): array
    {
        $quote = DemoRules::submittedQuote($product, $input);
        $id = bin2hex(random_bytes(16));
        Session::start();
        try {
            $recent = array_slice($_SESSION['garage_quotes'] ?? [], -7, null, true);
            $recent[$id] = $quote + ['expires' => time() + 900];
            $_SESSION['garage_quotes'] = $recent;
        } finally { session_write_close(); }
        return $quote + ['confirmationId' => $id];
    }

    public function save(string $projectId, array $product, array $input): array
    {
        $id = $input['confirmationId'] ?? null;
        $email = $input['email'] ?? null;
        if (array_diff(array_keys($input), ['confirmationId','email']) || !is_string($id) || !preg_match('/^[0-9a-f]{32}$/D', $id)
            || !is_string($email) || strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new HttpException(422, 'Invalid enquiry.', ['email' => ['Enter an email and use a confirmed configuration.']]);
        }
        Session::start();
        $confirmed = $_SESSION['garage_quotes'][$id] ?? null;
        session_write_close();
        if (!$confirmed || $confirmed['expires'] < time() || $confirmed['productId'] !== $product['id']
            || $confirmed['revision'] !== $product['revision']) throw new HttpException(409, 'Confirm the current configuration again.');
        $quote = DemoRules::quote($product, $confirmed['configuration']);
        if ($quote['amountMinor'] !== $confirmed['amountMinor']) throw new HttpException(409, 'The demo price has changed.');
        try {
            // Check the publication pointer in the same SQL statement as the insert.
            $save = $this->db->prepare('INSERT INTO garage_enquiries
                (confirmation_id,project_id,product_id,product_revision,email,configuration_json,amount_minor,currency,created_at)
                SELECT ?,p.project_id,p.id,p.published_revision,?,?,?,?,? FROM cms_products p
                WHERE p.project_id=? AND p.id=? AND p.published_revision=?');
            $save->execute([$id, $email, json_encode($quote['configuration'], JSON_THROW_ON_ERROR),
                $quote['amountMinor'], $quote['currency'], gmdate('c'), $projectId, $product['id'], $product['revision']]);
            if ($save->rowCount() !== 1) throw new HttpException(409, 'The published offer has changed. Confirm it again.');
        } catch (\PDOException $error) {
            if ($error->getCode() !== '23000') throw $error;
            $existing = $this->db->prepare('SELECT email FROM garage_enquiries WHERE confirmation_id=?');
            $existing->execute([$id]);
            if ($existing->fetchColumn() !== $email) throw new HttpException(409, 'An enquiry already exists for this confirmation.');
        }
        return ['enquiryId' => $id, 'amountMinor' => $quote['amountMinor'], 'currency' => $quote['currency']];
    }
}
