<?php

namespace Glavfinans\Domain\CommonContext\Services;

use Glavfinans\Core\Entity\CourtCollectionHistory\CourtCollectionCategoryValueType;
use Glavfinans\Core\Exception\NotFoundException;
use ICourtCollectionHistoryRepository;

/**
 * Сервис для получения loanId, clientId, staffId, data
 */
class CourtCollectionHistoryService
{

    /**
     * Метод для получения массива id без оплаты ГП по иску
     * для передачи в SpecialCommand
     *
     * @throws NotFoundException
     */
    public function getLoanIdsNoFlagPaymentStateDutyClaim(ICourtCollectionHistoryRepository $historyRepository): array
    {
        $thirdCategory = [
            CourtCollectionCategoryValueType::makeDecisionMade()->getValue(),
            CourtCollectionCategoryValueType::makeReceivedId()->getValue(),
        ];

        $unflaggedLoans = $historyRepository->findAllLoansWithoutFlagPaymentStateDutyClaim($thirdCategory);

        if (empty($unflaggedLoans)) {
            throw new NotFoundException(message: 'Не удалось получить займы без статуса# ' .
                CourtCollectionCategoryValueType::makePaymentStateDutyClaim()->getValue());
        }
        $loanIds = [];

        foreach ($unflaggedLoans as $history) {
            $loanIds[] = $history['loan_id'];
        }

        $flagDocsInCourtClaim = $historyRepository->findAllLoansFlagDocsInCourtClaim(
            loanIds: $loanIds,
            thirdCategory: $thirdCategory,
        );

        if (empty($flagDocsInCourtClaim)) {
            throw new NotFoundException(message: 'Не удалось получить займы по статусу# ' .
                CourtCollectionCategoryValueType::makeDocInCourtClaim()->getValue());
        }

        $flagInCourtClaim = [];

        foreach ($flagDocsInCourtClaim as $history) {
            $flagInCourtClaim[$history['loan_id']] = $history;
        }
        $flagInCourtClaim = array_unique(array: $flagInCourtClaim, flags: SORT_REGULAR);

        $thirdCategory = [
            CourtCollectionCategoryValueType::makeDecisionMade()->getValue(),
            CourtCollectionCategoryValueType::makeDocsInCourtClaim()->getValue(),
        ];

        $flagReceivedID = $historyRepository->findAllLoansFlagReceivedID(
            loanIds: $loanIds,
            thirdCategory: $thirdCategory,
        );

        if (empty($flagReceivedID)) {
            throw new NotFoundException(message: 'Не удалось получить займы по статусу# ' .
                CourtCollectionCategoryValueType::makeReceivedId()->getValue());
        }
        $flagReceived = [];

        foreach ($flagReceivedID as $history) {
            $flagReceived[$history['loan_id']] = $history;
        }

        $thirdCategory = [
            CourtCollectionCategoryValueType::makeReceivedId()->getValue(),
            CourtCollectionCategoryValueType::makeDocsInCourtClaim()->getValue(),
        ];

        $flagDecisionMade = $historyRepository->findAllLoansFlagDecisionMade(
            loanIds: $loanIds,
            thirdCategory: $thirdCategory,
        );

        if (empty($flagDecisionMade)) {
            throw new NotFoundException(message: 'Не удалось получить займы по статусу# ' .
                CourtCollectionCategoryValueType::makeDecisionMade()->getValue());
        }

        $flagDecision = [];

        foreach ($flagDecisionMade as $history) {
            $flagDecision[$history['loan_id']] = $history;
        }

        $flagReceived = array_diff_key($flagReceived, $flagDecision);

        return array_merge($flagReceived, $flagDecision, $flagInCourtClaim);
    }
}
