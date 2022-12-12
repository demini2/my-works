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
    public function isCanceledOrderByLoan(int $loanId): bool
    {
        return CourtCollectionHistory::model()->exists(
            'loan_id = :loanId AND second_category IN (:cancelCourtOrder, :cancelCourtOrderEP)',
            [
                ':loanId' => $loanId,
                ':cancelCourtOrder' => CourtCollectionHistory::CANCEL_COURT_ORDER,
                ':cancelCourtOrderEP' => CourtCollectionHistory::CANCEL_COURT_ORDER_EP,
            ]
        );
    }

    /**
     * @inheritDoc
     */
    public function isReceivedIDByLoan(int $loanId): bool
    {
        return CourtCollectionHistory::model()->exists(
            'loan_id = :loanId AND third_category = :receivedID',
            [
                ':loanId' => $loanId,
                ':receivedID' => CourtCollectionHistory::RECEIVED_ID,
            ]
        );
    }

    /**
     * @inheritDoc
     */
    public function findAllByLoanIdDeletedAtNull(int $loanId): array
    {
        return CourtCollectionHistory::model()
            ->findAllByAttributes(
                [
                    'loan_id' => $loanId,
                    'deleted_at' => null
                ],
                [
                    'order' => 'date ASC'
                ]
            );
    }

    /**
     * @inheritDoc
     */
    public function findAllByClientId(int $clientId): array
    {
        return CourtCollectionHistory::model()->findAllByAttributes(['client_id' => $clientId]);
    }

    /**
     * @inheritDoc
     */
    public function findByPk(int $pk): ?CourtCollectionHistory
    {
        return CourtCollectionHistory::model()->findByPk($pk);
    }

    /**
     * @inheritDoc
     */
    public function hasDecisionMadeByLoan(int $loanId): bool
    {
        $criteria = new CDbCriteria();
        $criteria->addCondition('`loan_id` = :loanId 
        AND `deleted_at` IS NULL 
        AND `first_category` = :courtClaim
        AND `second_category` = :answerByEP
        AND `third_category` = :decisionMade');

        $criteria->params = [
            ':loanId' => $loanId,
            ':courtClaim' => CourtCollectionHistory::COURT_CLAIM,
            ':answerByEP' => CourtCollectionHistory::ANSWER_BY_EP,
            ':decisionMade' => CourtCollectionHistory::DECISION_MADE,
        ];

        return CourtCollectionHistory::model()->exists($criteria);
    }

    /**
     * @inheritDoc
     */
    public function hasApplicationForPerformanceListSentByLoan(int $loanId, ?DateTimeImmutable $date = null): bool
    {
        $date ??= new DateTimeImmutable();

        $criteria = new CDbCriteria();
        $criteria->addCondition('`loan_id` = :loanId 
        AND `deleted_at` IS NULL 
        AND `first_category` = :courtClaim
        AND `second_category` = :applicationForPerformanceListSent
        AND DATE(`date`) = :date');

        $criteria->params = [
            ':loanId' => $loanId,
            ':courtClaim' => CourtCollectionHistory::COURT_CLAIM,
            ':applicationForPerformanceListSent' => CourtCollectionHistory::APPLICATION_FOR_PERFORMANCE_LIST_SENT,
            ':date' => $date->format(DateFmt::D_DB),
        ];

        return CourtCollectionHistory::model()->exists($criteria);
    }

    /**
     * @inheritDoc
     */
    public function hasPaymentStateDutyByLoan(int $loanId): bool
    {
        $criteria = new CDbCriteria();
        $criteria->addCondition('`loan_id` = :loanId 
        AND `deleted_at` IS NULL 
        AND `first_category` IN (:paymentStateDuty, :paymentStateDutyClaim)');

        $criteria->params = [
            ':loanId' => $loanId,
            ':paymentStateDuty' => CourtCollectionHistory::PAYMENT_STATE_DUTY,
            ':paymentStateDutyClaim' => CourtCollectionHistory::PAYMENT_STATE_DUTY_CLAIM,
        ];

        return CourtCollectionHistory::model()->exists($criteria);
    }

    /**
     * @inheritDoc
     */
    public function hasNoStateDutyId(): array
    {
        return CourtCollectionHistory::model()->findAllByAttributes(attributes: ['state_duty_id' => null]);
    }

    /**
     * @param DateTimeImmutable|null $date Дата установки статуса ($cch->getDate(), по умолчанию now
     *
     * @inheritDoc
     */
    public function findIdPrevPayedStateDuty(int $loanId, DateTimeInterface $date = null): ?int
    {
        $date = $date ?? new DateTimeImmutable();

        $sql = <<< SQL
                SELECT `state_duty_id` FROM `court_collection_history` WHERE 
                    `loan_id` = :loanId
                    AND `date` < :chDate
                    AND `first_category` = :dutyOrderPayed ORDER BY `date` DESC LIMIT 1
        SQL;

        $params = [
            ':chDate' => $date->format(format: DateFmt::DT_DB),
            ':loanId' => $loanId,
            ':dutyOrderPayed' => CourtCollectionHistory::PAYMENT_STATE_DUTY,
        ];

        return (CourtCollectionHistory::model()->findBySql(sql: $sql, params: $params))?->getStateDutyId();
    }

    /**
     * @inheritDoc
     */
    public function findAllByLoanIdForDay(int $loanId, DateTimeInterface $date): array
    {
        $sql = 'SELECT * FROM `court_collection_history`
                WHERE `loan_id` = :loanId
                AND `date` BETWEEN :dayStart AND :dayEnd
                AND `deleted_at` IS NULL
                ORDER BY `id` DESC ';
        $params = [
            ':loanId' => $loanId,
            ':dayStart' => $date->setTime(hour: 0, minute: 0)->format(format: DateFmt::DT_DB),
            ':dayEnd' => $date->format(format: DateFmt::DT_DB),
        ];

        return CourtCollectionHistory::model()->findAllBySql(sql: $sql, params: $params);
    }

    /**
     * @inheritDoc
     */
    public function findAllByLoanIdBeforeDate(int $loanId, DateTimeInterface $date): array
    {
        $sql = 'SELECT * FROM `court_collection_history`
                WHERE `loan_id` = :loanId
                AND `date` <= :dayEnd
                AND `deleted_at` IS NULL
                ORDER BY `id` DESC ';
        $params = [
            ':loanId' => $loanId,
            ':dayEnd' => $date->format(format: DateFmt::DT_DB),
        ];

        return CourtCollectionHistory::model()->findAllBySql(sql: $sql, params: $params);
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
