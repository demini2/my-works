<?php

use Glavfinans\Core\TaxService\TaxServiceClient;
use Glavfinans\Domain\CommonContext\Services\CourtCollectionHistoryService;

/**
 * Одноразовый скрипт
 * Class SpecialCommand
 */
class SpecialCommand extends GfkBaseConsoleCommand
{
    /**
     * Команда для проставления гос пошлины по иску в рамках задачи GFK-4373
     *
     * @param ICourtCollectionHistoryRepository $historyRepository
     * @param CourtCollectionHistoryService $collectionHistoryService
     * @return void
     * @throws Exception
     */
    public function actionPutTheFlagPaymentStateDutyClaim(
        ICourtCollectionHistoryRepository $historyRepository,
        CourtCollectionHistoryService     $collectionHistoryService,
    ): void {
        try {
            $errors = '';
            $transaction = InnerTransaction::begin();

            $arrayLoanIdsNoFlag = $collectionHistoryService
                ->getLoanIdsNoFlagPaymentStateDutyClaim(historyRepository: $historyRepository);

            foreach ($arrayLoanIdsNoFlag as $history) {
                $collectionHistory = new CourtCollectionHistory();
                $collectionHistory->setLoanId(loanId: $history['loan_id']);
                $collectionHistory->setClientId(clientId: $history['client_id']);
                $collectionHistory->setStaffId(staffId: $history['staff_id']);
                $collectionHistory->setFirstCategory(category: CourtCollectionCategoryValueType::makePaymentStateDutyClaim());
                $collectionHistory->setDate(date: DateTime::createFromFormat(
                    format: DateFmt::DT_DB,
                    datetime: $history['date'])
                );
                if (!$collectionHistory->save(false)) {
                    $errors = $errors . 'Не удалось сохранить# ' .
                        $collectionHistory->getErrorsString();
                }
            }

            if (empty($errors)) {
                echo "Статусы успешно проставлены!\n";
                $transaction->commit();
            } else {
                echo $errors . ' ';
                $transaction->rollback();
            }
        } catch (Exception $e) {
            echo $e->getMessage();

            $transaction->rollback();
        }
    }
}
