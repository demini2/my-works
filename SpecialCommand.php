<?php

use Cycle\ORM\EntityManagerInterface;
use Cycle\ORM\ORMInterface;
use Cycle\ORM\Transaction;
use Cycle\ORM\TransactionInterface;
use Glavfinans\Core\Addresses\AddressByDadata;
use Glavfinans\Core\Client\Inn;
use Glavfinans\Core\Client\SkibaApiClient;
use Glavfinans\Core\CreditHistory\EquifaxXmlFactory;
use Glavfinans\Core\DaData\DaDataClient;
use Glavfinans\Core\Documents\CantSaveFileException;
use Glavfinans\Core\Documents\Doc\DocAgreementNew;
use Glavfinans\Core\Documents\Doc\DocFormPODFT;
use Glavfinans\Core\Documents\Doc\DocNoPenalty;
use Glavfinans\Core\Documents\Doc\DocPact;
use Glavfinans\Core\Documents\Doc\DocProlong;
use Glavfinans\Core\Documents\Xls\XlsCreditHolidaysReportExport;
use Glavfinans\Core\Entity\Account\AccountRepositoryInterface;
use Glavfinans\Core\Entity\Account\RegistrationMode;
use Glavfinans\Core\Entity\Address\AddressService;
use Glavfinans\Core\Entity\Bankrupt\BankruptConfirmStatus;
use Glavfinans\Core\Entity\Bankrupt\BankruptFedParse\BankruptFedParse;
use Glavfinans\Core\Entity\Bankrupt\BankruptFedParse\BankruptFedParseRepositoryInterface;
use Glavfinans\Core\Entity\Bankrupt\BankruptRepositoryInterface;
use Glavfinans\Core\Entity\BankruptFedRequest\BankruptFedRequest;
use Glavfinans\Core\Entity\BankruptFedRequest\BankruptFedRequestRepositoryInterface;
use Glavfinans\Core\Entity\BankruptFedRequest\BankruptFedRequestResultFactory;
use Glavfinans\Core\Entity\Charge\ChargeRepositoryInterface;
use Glavfinans\Core\Entity\Comment\CommentRepositoryEntityInterface;
use Glavfinans\Core\Entity\CourtCollectionHistory\CourtCollectionCategoryValueType;
use Glavfinans\Core\Entity\CreditApplication\CreditApplicationRepositoryInterface;
use Glavfinans\Core\Entity\CreditHolidays\ICreditHolidaysRepository;
use Glavfinans\Core\Entity\EsiaRequestPersonData\EsiaRequestPersonDataRepositoryInterface;
use Glavfinans\Core\Entity\FsspRequest\FsspRequest;
use Glavfinans\Core\Entity\FsspRequest\FsspRequestRepositoryInterface;
use Glavfinans\Core\Entity\FsspRequest\FsspRequestStatus;
use Glavfinans\Core\Entity\IncomingTransfer\IncomingTransferRepositoryInterface;
use Glavfinans\Core\Entity\IncomingTransfer\PaymentDestination;
use Glavfinans\Core\Entity\IncomingTransfer\PaymentType;
use Glavfinans\Core\Entity\LeadPostback\ILeadPostbackRepository;
use Glavfinans\Core\Entity\LeadPostback\LeadPostback;
use Glavfinans\Core\Entity\Loan\LoanRepositoryInterface;
use Glavfinans\Core\Entity\LoanHistory\LoanHistoryRepositoryInterface;
use Glavfinans\Core\Entity\LoanRestructuring\LoanRestructuringRepositoryInterface;
use Glavfinans\Core\Entity\LoanSale\LoanSaleRepositoryInterface;
use Glavfinans\Core\Entity\Staff\IStaffRepository;
use Glavfinans\Core\Entity\WriteOffDebtPart\WriteOffDebtPartRepository;
use Glavfinans\Core\Entity\WriteOffDebtPart\WriteOffDebtPartRepositoryInterface;
use Glavfinans\Core\Exception\BaseException;
use Glavfinans\Core\Exception\EmptyValueException;
use Glavfinans\Core\Exception\NotFoundException;
use Glavfinans\Core\GFKCache\GFKCacheInterface;
use Glavfinans\Core\Insurance\FactoryInsuranceLife;
use Glavfinans\Core\Kernel\KernelInfo;
use Glavfinans\Core\Kernel\Task\ITaskPusher;
use Glavfinans\Core\Loan\Status;
use Glavfinans\Core\Recalc\Godzilla;
use Glavfinans\Core\Recalc\GodzillaFactory;
use Glavfinans\Core\Recalc\Models\Recalc;
use Glavfinans\Core\Report\Task\GenerateAverageSalary;
use Glavfinans\Core\Services\DialerWorkflowService;
use Glavfinans\Core\Services\LoanForStatService;
use Glavfinans\Core\Services\PercentRateService;
use Glavfinans\Core\TaxService\TaxServiceClient;
use Glavfinans\Domain\CommonContext\Services\CourtCollectionHistoryService;
use Glavfinans\Domain\ValueObject\Client\Gender;
use Glavfinans\Domain\ValueObject\Equifax\ReportVersion;
use Glavfinans\Repository\IncomingTransfer\IncomingTransferRepositoryInterface as IncomingTransferRepositoryInterfaceEntity;
use Glavfinans\Repository\Loan\LoanRepositoryInterface as LoanRepositoryInterfaceEntuty;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Одноразовый скрипт
 * Class SpecialCommand
 * php yiic.php Special checkLoanSum
 * php yiic.php Special checkBillSum
 * php yiic.php Special recalculateLoansAfter2015 --id=734    пересчёт займа с id=734
 * php yiic.php Special setSPD
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
