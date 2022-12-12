<?php

use Glavfinans\Core\Entity\CourtCollectionHistory\CourtCollectionCategoryValueType;
use Glavfinans\Core\Loan\InternalStatus;

/**
 * Репозиторий для CourtCollectionHistory
 */
class CourtCollectionHistoryRepository implements ICourtCollectionHistoryRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @inheritDoc
     */
    public function findAllLoansWithoutFlagPaymentStateDutyClaim(array $thirdCategory): array
    {
        $internalStatus = [
            InternalStatus::makeBankrupt()->getCode(),
            InternalStatus::makeDead()->getCode(),
            InternalStatus::makeTest()->getCode(),
        ];

        $thirdCategory = implode("', '", $thirdCategory);
        $internalStatus = implode(', ', $internalStatus);

        $sql = "SELECT `cch`.`client_id`, `cch`.`loan_id`, max(`date`) FROM court_collection_history AS `cch`
                        JOIN loan on `loan`.`id` = `cch`.`loan_id` 
                                                       WHERE `cch`.`loan_id` NOT IN (SELECT `loan_id` FROM court_collection_history
                                                       WHERE `first_category` = :paymentStateDutyClaim)
                        AND `cch`.`first_category` = :courtClaim AND `cch`.`third_category` IN ('$thirdCategory')
                        AND `loan`.`internal_status` NOT IN ($internalStatus)
                        GROUP BY `client_id`";
        $params = [
            ':courtClaim' => CourtCollectionCategoryValueType::makeCourtClaim()->getValue(),
            ':paymentStateDutyClaim' => CourtCollectionCategoryValueType::makePaymentStateDutyClaim()->getValue()
        ];

        $query = $this->pdo->prepare($sql);
        $query->execute($params);

        return $query->fetchAll();
    }

    /**
     * @inheritDoc
     */
    public function findAllLoansFlagDocsInCourtClaim(array $loanIds, array $thirdCategory): array
    {
        $thirdCategory = implode("', '", $thirdCategory);
        $loanIds = implode(separator: ', ', array: $loanIds);

        $sql = "SELECT DISTINCT `c`.`client_id`, `c`.`loan_id`, `c`.`staff_id`, `c`.`date` FROM court_collection_history AS `c`
                                WHERE `c`.`loan_id` IN($loanIds)
                                  AND (`c`.`second_category` = :docsInCourtClaim  AND `c`.`deleted_at` IS NULL)
                                AND `c`.`loan_id` NOT IN (SELECT `loan_id` FROM court_collection_history
                                                          WHERE `deleted_at` IS NULL
                                                          AND (`first_category` = :paymentStateDutyClaim
                                                               OR (`second_category` = :answerByEp 
                                                                       AND `third_category` in ('$thirdCategory')))
                                                          AND `date` < `c`.`date`)";

        $params = [
            ':paymentStateDutyClaim' => CourtCollectionCategoryValueType::makePaymentStateDutyClaim()->getValue(),
            ':docsInCourtClaim' => CourtCollectionCategoryValueType::makeDocInCourtClaim()->getValue(),
            ':answerByEp' => CourtCollectionCategoryValueType::makeAnswerByEp()->getValue(),
        ];

        $query = $this->pdo->prepare($sql);
        $query->execute($params);

        return $query->fetchAll();
    }

    /**
     * @inheritDoc
     */
    public function findAllLoansFlagReceivedID(array $loanIds, array $thirdCategory): array
    {
        $thirdCategory = implode("', '", $thirdCategory);
        $loanIds = implode(separator: ', ', array: $loanIds);

        $sql = "SELECT DISTINCT `c`.`client_id`, `c`.`loan_id`, `c`.`staff_id`, `c`.`date` FROM court_collection_history AS `c`
                                WHERE `c`.`loan_id` IN($loanIds)
                                AND (`c`.`second_category` = :answerByEp
                                         AND `c`.`third_category` = :receivedID AND `c`.`deleted_at` IS NULL)
                                AND `c`.`loan_id` NOT IN (SELECT `loan_id` FROM court_collection_history
                                                          WHERE `deleted_at` IS NULL
                                                          AND (`first_category` = :paymentStateDutyClaim
                                                               OR (`second_category` IN ('$thirdCategory')))
                                                          AND `date` < `c`.`date`)";

        $params = [
            ':answerByEp' => CourtCollectionCategoryValueType::makeAnswerByEp()->getValue(),
            ':receivedID' => CourtCollectionCategoryValueType::makeReceivedId()->getValue(),
            ':paymentStateDutyClaim' => CourtCollectionCategoryValueType::makePaymentStateDutyClaim()->getValue(),
        ];

        $query = $this->pdo->prepare($sql);
        $query->execute($params);

        return $query->fetchAll();
    }

    /**
     * @inheritDoc
     */
    public function findAllLoansFlagDecisionMade(array $loanIds, array $thirdCategory): array
    {

        $thirdCategory = implode("', '", $thirdCategory);
        $loanIds = implode(separator: ', ', array: $loanIds);

        $sql = "SELECT DISTINCT `c`.`client_id`, `c`.`loan_id`, `c`.`staff_id`, `c`.`date` FROM court_collection_history AS `c`
                                WHERE `c`.`loan_id` in($loanIds)
                                AND (`c`.`second_category` = :answerByEp
                                         AND `c`.`third_category` = :DecisionMade AND `c`.`deleted_at` IS NULL)
                                AND `c`.`loan_id` NOT IN(SELECT `loan_id` FROM court_collection_history 
                                                          WHERE `deleted_at` IS NULL 
                                                          AND (`first_category` = :paymentStateDutyClaim 
                                                               OR (`second_category` IN ('$thirdCategory')))
                                                          AND `date` < `c`.`date`)";
        $params = [
            ':answerByEp' => CourtCollectionCategoryValueType::makeAnswerByEp()->getValue(),
            ':DecisionMade' => CourtCollectionCategoryValueType::makeDecisionMade()->getValue(),
            ':paymentStateDutyClaim' => CourtCollectionCategoryValueType::makePaymentStateDutyClaim()->getValue(),
        ];

        $query = $this->pdo->prepare($sql);
        $query->execute($params);

        return $query->fetchAll();
    }
}
