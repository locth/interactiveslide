<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Vietnamese strings for mod_interactiveslide.
 *
 * @package    mod_interactiveslide
 * @copyright  2026 Interactive Slide contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Interactive Slide';
$string['modulename'] = 'Slide tương tác';
$string['modulenameplural'] = 'Slide tương tác';
$string['modulename_help'] = 'Nhập một file PDF, hệ thống tự tách thành các slide. Giảng viên gắn Wordcloud, câu hỏi trắc nghiệm, điền vào chỗ trống hoặc câu hỏi mở cho slide bất kỳ rồi trình chiếu trực tiếp. Sinh viên xem đúng slide giảng viên đang chiếu trên thiết bị của mình, trả lời khi câu hỏi còn mở và tích lũy sao trên bảng xếp hạng.';
$string['pluginadministration'] = 'Quản trị Slide tương tác';
$string['activityname'] = 'Tên hoạt động';
$string['noinstances'] = 'Khóa học này chưa có hoạt động Slide tương tác nào.';

// Capabilities.
$string['interactiveslide:addinstance'] = 'Thêm hoạt động Slide tương tác';
$string['interactiveslide:view'] = 'Xem hoạt động Slide tương tác';
$string['interactiveslide:manage'] = 'Nhập slide và chỉnh sửa tương tác';
$string['interactiveslide:present'] = 'Điều phối phiên trình chiếu';
$string['interactiveslide:submit'] = 'Trả lời tương tác với tư cách người tham gia';
$string['interactiveslide:viewreports'] = 'Xem báo cáo sao';
$string['interactiveslide:awardstars'] = 'Tặng sao thưởng thủ công';

// Activity settings form.
$string['gamification'] = 'Sao và bảng xếp hạng';
$string['showleaderboard'] = 'Hiển thị bảng xếp hạng';
$string['showleaderboard_help'] = 'Ai được xem bảng xếp hạng trong phiên. Giảng viên luôn thấy bảng đầy đủ.';
$string['leaderboardteacheronly'] = 'Chỉ giảng viên';
$string['leaderboardeveryone'] = 'Mọi người xem bảng đầy đủ';
$string['leaderboardownrank'] = 'Sinh viên chỉ thấy thứ hạng của mình';
$string['leaderboardsize'] = 'Số hạng hiển thị cho sinh viên';
$string['speedbonus'] = 'Thưởng trả lời nhanh';
$string['speedbonus_help'] = 'Cộng thêm sao khi trả lời đúng và còn dư thời gian. Cần bật hẹn giờ cho câu hỏi: câu hỏi không hẹn giờ thì không có mốc để đo nên không bao giờ được thưởng.';
$string['speedbonusmax'] = 'Số sao thưởng tối đa';
$string['attendancestars'] = 'Sao cho việc tham gia phiên';
$string['attendancestars_help'] = 'Tặng một lần cho mỗi sinh viên khi vào phiên, trước khi trả lời bất cứ câu nào. Số sao này cộng vào tổng sao của sinh viên qua các phiên, hiện trong báo cáo và sổ điểm. Đặt 0 nếu chỉ muốn thưởng theo câu trả lời.';
$string['attendancestarsshort'] = 'Tham gia';
$string['waitingforslides'] = 'Chưa có slide trên màn hình';
$string['unsavedinteraction'] = 'Rời đi mà không lưu?';
$string['unsavedinteraction_desc'] = 'Slide này có thay đổi tương tác chưa được lưu. Chuyển sang slide khác sẽ mất các thay đổi đó.';
$string['discardchanges'] = 'Bỏ thay đổi';
$string['anonymousresults'] = 'Bảng xếp hạng ẩn danh với sinh viên';
$string['anonymousresults_help'] = 'Khi bật, sinh viên thấy bảng xếp hạng không kèm tên người khác, nhưng vẫn thấy dòng của chính mình. Màn hình trình chiếu và báo cáo thì luôn hiện tên. Kết quả tổng hợp như Wordcloud vốn không kèm tên trong mọi trường hợp.';
$string['allowlatejoin'] = 'Cho phép vào muộn';
$string['allowlatejoin_help'] = 'Sinh viên vào sau khi phiên đã bắt đầu vẫn tham gia được và trả lời các câu hỏi còn đang mở.';
$string['grademethod'] = 'Cách quy đổi sao thành điểm';
$string['grademethod_help'] = 'Cách chuyển số sao sinh viên tích lũy thành điểm trong sổ điểm. Điểm được ghi khi phiên kết thúc.';
$string['grademethodtotal'] = 'Tổng số sao, so với các phiên đã tham gia';
$string['grademethodbest'] = 'Phiên tốt nhất';
$string['grademethodlast'] = 'Phiên gần nhất';
$string['grademethodrelative'] = 'Tổng số sao, so với sinh viên dẫn đầu';

// Site settings.
$string['settingsdifficulty'] = 'Số sao mặc định theo độ khó';
$string['settingsdifficulty_desc'] = 'Mỗi mức độ khó đáng bao nhiêu sao khi giảng viên chọn Dễ, Trung bình hoặc Khó.';
$string['difficulty'] = 'Độ khó';
$string['difficultyeasy'] = 'Dễ';
$string['difficultymedium'] = 'Trung bình';
$string['difficultyhard'] = 'Khó';
$string['points_easy_desc'] = 'Số sao khi trả lời đúng câu hỏi mức Dễ.';
$string['points_medium_desc'] = 'Số sao khi trả lời đúng câu hỏi mức Trung bình.';
$string['points_hard_desc'] = 'Số sao khi trả lời đúng câu hỏi mức Khó.';
$string['settingslive'] = 'Phiên trực tiếp';
$string['settingslive_desc'] = 'Tần suất màn hình sinh viên và giảng viên hỏi máy chủ xem có gì thay đổi, và độ lớn của ảnh slide khi nhập PDF.';
$string['pollinterval'] = 'Chu kỳ cập nhật';
$string['pollinterval_desc'] = 'Chu kỳ ngắn cho cảm giác tức thời hơn nhưng tốn một yêu cầu cho mỗi người tham gia mỗi chu kỳ. 2 giây là phù hợp với lớp khoảng 150 sinh viên trở xuống.';
$string['pollinterval1'] = '1 giây';
$string['pollinterval2'] = '2 giây (khuyến nghị)';
$string['pollinterval3'] = '3 giây';
$string['pollinterval5'] = '5 giây';
$string['renderscale'] = 'Kích thước ảnh slide';
$string['renderscale_desc'] = 'Cạnh dài nhất, tính bằng pixel, của mỗi ảnh trang khi nhập PDF. Ảnh lớn hơn thì nét hơn khi chiếu máy chiếu nhưng tải lên lâu hơn.';
$string['imageformat'] = 'Định dạng ảnh slide';
$string['imageformat_desc'] = 'Cách mã hoá mỗi trang khi nhập PDF. Mỗi sinh viên tải về từng slide mà họ được xem, nên đây là chi phí băng thông lớn nhất của một buổi đông người. Một slide mẫu có nền chuyển màu nặng khoảng 1,7MB nếu là PNG, so với 140KB ở JPEG 85 và 90KB ở JPEG 70. Chỉ giữ PNG cho những bộ slide có chữ nhỏ cần thật sắc nét.';
$string['imageformatjpeg85'] = 'JPEG, chất lượng 85 (khuyến nghị)';
$string['imageformatjpeg70'] = 'JPEG, chất lượng 70 (nhẹ nhất)';
$string['imageformatpng'] = 'PNG, không mất dữ liệu (nặng nhất)';
$string['studentslides'] = 'Hiện slide trên máy sinh viên';
$string['studentslides_desc'] = 'Khi tắt, sinh viên chỉ thấy câu hỏi và ô trả lời, còn slide thì nhìn lên máy chiếu. Đường dẫn ảnh không hề được gửi xuống trang của họ nên không có gì để tải. Phù hợp với lớp đông hoặc wifi giảng đường yếu, nhưng không nên dùng cho bộ slide mà câu hỏi phụ thuộc vào sơ đồ cần nhìn kỹ.';
$string['studentslidesshow'] = 'Hiện slide trên máy sinh viên';
$string['studentslideshide'] = 'Chỉ hiện phần trả lời, sinh viên nhìn máy chiếu';
$string['watchtheprojector'] = 'Hãy nhìn lên màn chiếu';
$string['watchtheprojector_desc'] = 'Hoạt động này chiếu slide trên màn hình lớp. Phần trả lời sẽ hiện ở đây khi giảng viên mở câu hỏi.';
$string['maxpages'] = 'Số trang tối đa mỗi lần nhập';
$string['maxpages_desc'] = 'Các trang vượt quá giới hạn này sẽ bị bỏ qua, tránh một file PDF cả nghìn trang nhập nhầm làm đầy kho tệp.';

// Teacher hub.
$string['slides'] = 'Slide';
$string['interactions'] = 'Tương tác';
$string['maxstarsperrun'] = 'Số sao tối đa mỗi lượt chạy';
$string['present'] = 'Trình chiếu';
$string['resumepresenting'] = 'Quay lại bảng điều khiển';
$string['editslides'] = 'Chỉnh sửa slide';
$string['addslide'] = 'Thêm slide';
$string['notanimage'] = 'Tệp này không phải là hình ảnh.';
$string['uploading'] = 'Đang tải lên...';
$string['slideadded'] = 'Đã thêm slide vào cuối bộ slide.';
$string['uploadfailed'] = 'Tải lên thất bại.';
$string['importpdf'] = 'Nhập PDF';
$string['starsinactivity'] = 'Số sao trong hoạt động này';
$string['starsincourse'] = 'Số sao trong toàn khóa học';
$string['sessionsjoined'] = 'Qua {$a} phiên';
$string['acrossactivities'] = 'Trên {$a} hoạt động';
$string['joinsession'] = 'Bắt đầu tham gia';
$string['backtostars'] = 'Quay lại xem số sao';
$string['sessionlivenow'] = 'Đang có một phiên diễn ra';
$string['myreports'] = 'Báo cáo của tôi';
$string['myreports_desc'] = 'Báo cáo này chỉ hiển thị kết quả của riêng bạn.';
$string['deletesession'] = 'Xóa phiên này';
$string['deletesession_confirm'] = 'Xóa phiên "{$a->name}"?';
$string['deletesession_desc'] = 'Thao tác này xóa toàn bộ câu trả lời trong phiên và {$a->stars} sao đã cộng cho {$a->participants} người tham gia. Tổng sao của họ trong hoạt động và trong khóa học sẽ giảm tương ứng. Không thể hoàn tác.';
$string['deletesession_done'] = 'Đã xóa phiên cùng số sao mà phiên đó đã cộng.';
$string['errordeleteactivesession'] = 'Phiên này đang diễn ra. Hãy kết thúc phiên trước rồi mới xóa.';
$string['reports'] = 'Báo cáo';
$string['overallleaderboard'] = 'Bảng xếp hạng tổng hợp mọi phiên';
$string['noslidesyet'] = 'Chưa có slide nào';
$string['noslidesyet_desc'] = 'Nhập một file PDF, mỗi trang sẽ thành một slide và bạn có thể gắn tương tác vào đó.';
$string['sessionstatus'] = 'Phiên';
$string['sessionlive'] = 'Đang diễn ra';
$string['sessionidle'] = 'Chưa chạy';
$string['joincode'] = 'Mã phòng';
$string['participantsjoined'] = '{$a} người đã vào';
$string['starsx'] = '{$a} sao';

// Editor.
$string['interaction'] = 'Tương tác';
$string['nointeractionyet'] = 'Slide này chưa có tương tác.';
$string['removeinteraction'] = 'Xóa tương tác';
$string['clearresponses'] = 'Xóa câu trả lời và mở khóa';
$string['confirmclearresponses'] = 'Xóa các câu trả lời của câu hỏi này?';
$string['confirmclearresponses_desc'] = 'Toàn bộ câu trả lời của sinh viên cho câu hỏi này sẽ bị xóa và số sao đã cộng bị thu hồi, nhờ đó bạn chỉnh sửa lại câu hỏi được. Các slide khác không bị ảnh hưởng. Không thể hoàn tác.';
$string['responsescleared'] = 'Đã xóa {$a} câu trả lời. Câu hỏi có thể chỉnh sửa lại.';
$string['errorsessionrunning'] = 'Hãy kết thúc phiên đang chạy trước khi xóa câu trả lời.';
$string['interactionlockednote'] = 'Sinh viên đã trả lời câu hỏi này nên nó đang bị khóa. Hãy xóa các câu trả lời đó để chỉnh sửa lại; các slide khác không bị ảnh hưởng.';
$string['questiontype'] = 'Loại câu hỏi';
$string['typenone'] = 'Không tương tác';
$string['typewordcloud'] = 'Wordcloud';
$string['typemultichoice'] = 'Trắc nghiệm';
$string['typedropdown'] = 'Danh sách thả xuống';
$string['typevideo'] = 'Video';
$string['video'] = 'Video';
$string['novideo'] = 'Slide này chưa được gán video.';
$string['videourl'] = 'Liên kết video';
$string['videourl_help'] = 'Liên kết YouTube hoặc Vimeo, hoặc liên kết trực tiếp tới tệp .mp4, .webm hay .ogv.';
$string['videourlplaceholder'] = 'https://www.youtube.com/watch?v=...';
$string['errorvideourl'] = 'Không nhúng được liên kết này. Hãy dùng liên kết YouTube hoặc Vimeo, hoặc liên kết trực tiếp tới tệp .mp4, .webm hay .ogv.';
$string['errornosubmission'] = 'Tương tác này không nhận câu trả lời.';
$string['chooseanswer'] = 'Chọn một đáp án...';
$string['typefillblank'] = 'Điền vào chỗ trống';
$string['typeopenended'] = 'Câu hỏi mở';
$string['maxanswerlength'] = 'Số ký tự mỗi câu trả lời';
$string['openendedplaceholder'] = 'Viết câu trả lời của bạn';
$string['charactersleft'] = 'Còn {$a} ký tự';
$string['reloadneeded'] = 'Câu hỏi này cần bản mới hơn của trang. Hãy tải lại để trả lời.';
$string['reloadpage'] = 'Tải lại trang';
$string['questiontext'] = 'Câu hỏi';
$string['questiontextplaceholder'] = 'Bạn muốn hỏi gì? Để trống nếu câu hỏi đã có sẵn trên slide.';
$string['maxentries'] = 'Số từ mỗi sinh viên';
$string['maxwordlength'] = 'Số ký tự mỗi từ';
$string['participationstars'] = 'Sao cho việc tham gia';
$string['hasanswer'] = 'Câu hỏi có đáp án';
$string['allowmultiple'] = 'Cho phép chọn nhiều phương án';
$string['allowmultiple_hint'] = 'Dùng cho câu bình chọn hoặc khi có nhiều lựa chọn hợp lệ, kể cả câu không có đáp án đúng. Nếu câu hỏi có bật đáp án, sinh viên phải chọn đúng trọn bộ phương án bạn đã đánh dấu.';
$string['showliveresult'] = 'Hiện kết quả trực tiếp trên màn chiếu khi sinh viên đang trả lời';
$string['showleaderboardafter'] = 'Hiện bảng xếp hạng sau khi công bố đáp án';
$string['allowretry'] = 'Cho phép sinh viên đổi câu trả lời khi câu hỏi còn mở';
$string['options'] = 'Phương án';
$string['addoption'] = 'Thêm phương án';
$string['optionplaceholder'] = 'Nội dung phương án';
$string['correct'] = 'Đúng';
$string['remove'] = 'Xóa';
$string['positions'] = 'Các vị trí thả xuống';
$string['position'] = 'Vị trí';
$string['gapshint'] = 'Gõ ___ (ba dấu gạch dưới) trong câu hỏi tại nơi cần đặt danh sách thả xuống. Mỗi chỗ trống thành một vị trí, theo thứ tự.';
$string['errorneedoneposition'] = 'Hãy thêm ít nhất một vị trí thả xuống.';
$string['errorpositionneedstwooptions'] = 'Vị trí {$a} cần ít nhất hai phương án.';
$string['errorpositionneedsanswer'] = 'Hãy đánh dấu phương án đúng cho vị trí {$a}, hoặc tắt "Câu hỏi này có đáp án đúng".';
$string['errorpositiononecorrect'] = 'Vị trí {$a} chỉ được có một phương án đúng: danh sách thả xuống chỉ nhận một đáp án.';
$string['blanks'] = 'Các chỗ trống';
$string['blank'] = 'Chỗ trống';
$string['addblank'] = 'Thêm chỗ trống';
$string['blanklabelplaceholder'] = 'Chỗ trống này điền gì?';
$string['acceptedanswers'] = 'Đáp án chấp nhận, mỗi dòng một đáp án';
$string['answersplaceholder'] = 'Mỗi dòng một đáp án được chấp nhận';
$string['points'] = 'Sao';
$string['timerseconds'] = 'Hẹn giờ (giây)';
$string['timerseconds_help'] = '0 nghĩa là không hẹn giờ. Máy chủ tự đóng câu hỏi khi hết giờ, nên tạm dừng trang cũng không được thêm thời gian.';
$string['saveinteraction'] = 'Lưu tương tác';
$string['slidetitle'] = 'Tiêu đề slide';
$string['slidetitleplaceholder'] = 'Không bắt buộc, dùng trong báo cáo';
$string['deleteslide'] = 'Xóa slide';
$string['saving'] = 'Đang lưu…';
$string['saved'] = 'Đã lưu';
$string['savefailed'] = 'Không lưu được';
$string['importing'] = 'Đang nhập';
$string['importprogress'] = 'Trang {$a->done} / {$a->total}';
$string['importdone'] = 'Đã nhập {$a} slide';
$string['importfailed'] = 'Nhập file thất bại.';
$string['notapdf'] = 'Vui lòng chọn một file PDF.';
$string['pdfjsmissing'] = 'Không tải được bộ đọc PDF. Hãy tải pdfjs-<phiên bản>-dist.zip từ github.com/mozilla/pdf.js/releases rồi chép hai file build/pdf.mjs và build/pdf.worker.mjs vào mod/interactiveslide/thirdparty/pdfjs/. Nếu đã chép rồi, hãy kiểm tra xem mod/interactiveslide/pdfjs.php có truy cập được không: plugin phục vụ pdf.js qua file này để chạy được cả trên máy chủ không gửi kiểu MIME JavaScript cho .mjs. Xem thêm README trong thư mục đó.';
$string['confirmdeleteslide'] = 'Xóa slide này?';
$string['confirmdeleteslide_desc'] = 'Slide, tương tác của nó và toàn bộ câu trả lời đã thu sẽ bị xóa. Không thể hoàn tác.';
$string['confirmdeleteinteraction'] = 'Xóa tương tác này?';
$string['confirmdeleteinteraction_desc'] = 'Câu hỏi và toàn bộ câu trả lời đã thu sẽ bị xóa. Không thể hoàn tác.';
$string['confirmreplacedeck'] = 'Thay toàn bộ bộ slide?';
$string['confirmreplacedeck_desc'] = 'Nhập PDF sẽ thay thế mọi slide trong hoạt động này, kèm theo các tương tác và câu trả lời gắn với chúng.';

// Presenter console.
$string['annotateoff'] = 'Ngừng vẽ';
$string['annotatelaser'] = 'Bút laser';
$string['annotatelaserdot'] = 'Chấm';
$string['annotatelaserline'] = 'Vệt';
$string['annotatepen'] = 'Bút';
$string['annotatehighlight'] = 'Bút dạ quang';
$string['annotateeraser'] = 'Tẩy';
$string['annotateclear'] = 'Xóa hết trên slide';
$string['annotatestylusonly'] = 'Chỉ nhận bút cảm ứng: ngón tay dùng để cuộn, không vẽ';
$string['annotatethin'] = 'Mảnh';
$string['annotatemedium'] = 'Vừa';
$string['annotatethick'] = 'Đậm';
$string['colourblue'] = 'Xanh dương';
$string['colourred'] = 'Đỏ';
$string['colouryellow'] = 'Vàng';
$string['colourgreen'] = 'Xanh lá';
$string['colourpink'] = 'Hồng';
$string['backtoactivity'] = 'Quay lại hoạt động';
$string['startsession'] = 'Bắt đầu phiên';
$string['endsession'] = 'Kết thúc phiên';
$string['sessionstarted'] = 'Đã bắt đầu phiên';
$string['sessionended'] = 'Đã kết thúc phiên';
$string['sessionended_desc'] = 'Giảng viên đã kết thúc phiên này. Số sao của bạn đã được lưu.';
$string['confirmendsession'] = 'Kết thúc phiên này?';
$string['confirmendsession_desc'] = 'Sinh viên sẽ không còn thấy slide nữa và số sao sẽ được ghi vào sổ điểm.';
$string['confirmreset'] = 'Xóa kết quả và chạy lại';
$string['confirmreset_desc'] = 'Toàn bộ câu trả lời đã thu cho câu hỏi này sẽ bị xóa và số sao đã cộng sẽ bị thu hồi, để chạy lại câu hỏi từ đầu.';
$string['startinteraction'] = 'Bắt đầu';
$string['stopcollecting'] = 'Ngừng nhận câu trả lời';
$string['revealanswer'] = 'Công bố đáp án';
$string['hideanswer'] = 'Ẩn đáp án';
$string['showresultscreen'] = 'Hiện kết quả';
$string['hideresultscreen'] = 'Ẩn kết quả';
$string['pushresult'] = 'Gửi kết quả về máy sinh viên';
$string['hideresult'] = 'Thu kết quả khỏi máy sinh viên';
$string['resetround'] = 'Xóa kết quả và chạy lại';
$string['leaderboard'] = 'Bảng xếp hạng';
$string['awardstar'] = 'Tặng một sao';
$string['awardreason'] = 'Tặng trong phiên trình chiếu';
$string['starawarded'] = 'Đã tặng sao';
$string['fullscreen'] = 'Toàn màn hình';
$string['hideoverlay'] = 'Ẩn lớp phủ';
$string['previousslide'] = 'Slide trước';
$string['nextslide'] = 'Slide sau';
$string['currentslide'] = 'Slide hiện tại';
$string['roundopened'] = 'Đang nhận câu trả lời';
$string['collectinganswers'] = 'Đang thu câu trả lời. Kết quả chỉ hiện khi bạn cho hiện.';
$string['roundclosedtoast'] = 'Đã đóng câu trả lời';
$string['answerrevealed'] = 'Đã công bố đáp án';
$string['responsesreceived'] = 'Số câu trả lời nhận được trên tổng số người đã vào';
$string['participantsonline'] = 'Đang trực tuyến';
$string['nosessionyet'] = 'Chưa có phiên nào chạy';
$string['nosessionyet_desc'] = 'Bắt đầu một phiên và sinh viên sẽ thấy đúng slide bạn đang chiếu.';
$string['slidenumber'] = 'Slide {$a}';

// Student player.
$string['connecting'] = 'Đang kết nối';
$string['online'] = 'Trực tiếp';
$string['offline'] = 'Đang kết nối lại';
$string['waitingforteacher'] = 'Đang chờ giảng viên';
$string['waitingforteacher_desc'] = 'Slide sẽ hiện ở đây ngay khi phiên bắt đầu. Hãy giữ trang này mở.';
$string['waitingfornextquestion'] = 'Đang chờ câu hỏi tiếp theo';
$string['submitanswer'] = 'Gửi';
$string['changeanswer'] = 'Đổi câu trả lời';
$string['answersubmitted'] = 'Đã gửi câu trả lời';
$string['answerrecorded'] = 'Câu trả lời của bạn đã được ghi nhận.';
$string['roundclosed'] = 'Câu hỏi này đã đóng.';
$string['timesup'] = 'Đã hết giờ.';
$string['youwerecorrect'] = 'Chính xác!';
$string['youwereincorrect'] = 'Lần này chưa đúng.';
$string['starsearned'] = 'Số sao nhận được';
$string['selectanswerfirst'] = 'Hãy nhập câu trả lời trước.';
$string['wordplaceholder'] = 'Từ khóa';
$string['blankplaceholder'] = 'Câu trả lời của bạn';
$string['yourrank'] = 'Thứ hạng của bạn';
$string['liveresult'] = 'Kết quả trực tiếp';
$string['correctanswer'] = 'Đáp án đúng';
$string['nowordsyet'] = 'Chưa có từ nào.';
$string['noanswersyet'] = 'Chưa có câu trả lời nào.';
$string['noparticipantsyet'] = 'Chưa có ai tham gia.';
$string['anonymousparticipant'] = 'Một người tham gia';
$string['deleteduser'] = 'Người dùng đã xóa';

// Reports.
$string['reportcourse'] = 'Tất cả bộ slide trong khóa học (tổng sao)';
$string['reportoverview'] = 'Tất cả các phiên';
$string['reportsession'] = 'Phiên: {$a}';
$string['chooseview'] = 'Hiển thị';
$string['useridnumber'] = 'Mã số';
$string['participant'] = 'Người tham gia';
$string['sessionsattended'] = 'Số phiên';
$string['stars'] = 'Sao';
$string['correctanswers'] = 'Đúng';
$string['answersgiven'] = 'Đã trả lời';
$string['questionstars'] = 'Sao câu hỏi';
$string['totalstars'] = 'Tổng sao';
$string['manualstarsshort'] = 'Sao thưởng';
$string['beststreak'] = 'Chuỗi đúng dài nhất';
$string['noresponsesyet'] = 'Chưa có ai trả lời gì cả.';
$string['exportresults'] = 'Tải kết quả này về';
$string['resetsessions'] = 'Xóa toàn bộ phiên, câu trả lời và sao';

// Events.
$string['eventsessionstarted'] = 'Đã bắt đầu phiên';
$string['eventsessiondeleted'] = 'Đã xóa phiên';
$string['eventsessionended'] = 'Đã kết thúc phiên';
$string['eventroundopened'] = 'Đã mở tương tác';
$string['eventroundclosed'] = 'Đã đóng tương tác';
$string['eventresponsesubmitted'] = 'Đã gửi câu trả lời';
$string['eventstarsawarded'] = 'Đã tặng sao';

// Errors.
$string['errorinvalidqtype'] = 'Hoạt động này không hỗ trợ loại câu hỏi đó.';
$string['errorinvalidaction'] = 'Thao tác này không khả dụng.';
$string['errorinvalidpayload'] = 'Không đọc được định nghĩa câu hỏi.';
$string['errorneedtwooptions'] = 'Câu hỏi trắc nghiệm cần ít nhất hai phương án có nội dung.';
$string['errorneedcorrectoption'] = 'Hãy đánh dấu ít nhất một phương án đúng, hoặc tắt tùy chọn "Câu hỏi có đáp án".';
$string['errortoomanycorrect'] = 'Chỉ được một phương án đúng, trừ khi bạn cho phép nhiều đáp án.';
$string['errorneedoneblank'] = 'Hãy thêm ít nhất một chỗ trống.';
$string['errorblankneedsanswer'] = 'Chỗ trống {$a} chưa có đáp án nào.';
$string['errorinteractionlocked'] = 'Sinh viên đã trả lời câu hỏi này nên không thể chỉnh sửa nữa.';
$string['errorslidenotfound'] = 'Slide đó không thuộc hoạt động này.';
$string['errorsessionnotfound'] = 'Phiên đó không thuộc hoạt động này.';
$string['errornointeraction'] = 'Slide này không có tương tác nào để chạy.';
$string['errornosession'] = 'Hiện không có phiên nào đang chạy.';
$string['errorlatejoin'] = 'Phiên đã bắt đầu trước khi bạn vào, và hoạt động này không cho phép tham gia muộn.';
$string['errornoround'] = 'Slide này chưa chạy tương tác nào.';
$string['errorsessionended'] = 'Phiên này đã kết thúc.';
$string['errorroundclosed'] = 'Câu hỏi này không còn nhận câu trả lời.';
$string['errorroundnotcurrent'] = 'Lớp đã chuyển sang slide khác.';
$string['erroralreadyanswered'] = 'Bạn đã trả lời câu hỏi này rồi.';
$string['erroremptyanswer'] = 'Hãy nhập câu trả lời trước.';
$string['errorsinglechoiceonly'] = 'Chỉ được chọn một phương án.';
$string['errornotparticipant'] = 'Người dùng đó không thể tham gia hoạt động này.';
$string['errortoomanypages'] = 'File PDF này có hơn {$a} trang, vượt giới hạn của hệ thống.';
$string['erroruploadfailed'] = 'Không tải được ảnh trang lên.';
$string['errornotanimage'] = 'Trang được tải lên không phải ảnh PNG hợp lệ.';

// Privacy.
$string['privacy:metadata:participant'] = 'Số liệu tích lũy của một người tham gia trong một phiên trực tiếp.';
$string['privacy:metadata:participant:userid'] = 'Người tham gia.';
$string['privacy:metadata:participant:totalstars'] = 'Số sao tích lũy trong phiên.';
$string['privacy:metadata:participant:correctcount'] = 'Số câu trả lời đúng.';
$string['privacy:metadata:participant:responsecount'] = 'Số câu đã trả lời.';
$string['privacy:metadata:participant:timejoined'] = 'Thời điểm tham gia phiên.';
$string['privacy:metadata:participant:lastseen'] = 'Lần hoạt động gần nhất.';
$string['privacy:metadata:response'] = 'Một lượt trả lời cho một câu hỏi.';
$string['privacy:metadata:response:userid'] = 'Người gửi câu trả lời.';
$string['privacy:metadata:response:iscorrect'] = 'Câu trả lời có hoàn toàn đúng hay không.';
$string['privacy:metadata:response:stars'] = 'Số sao được cộng cho câu trả lời.';
$string['privacy:metadata:response:timetaken'] = 'Thời gian trả lời.';
$string['privacy:metadata:response:timecreated'] = 'Thời điểm gửi câu trả lời.';
$string['privacy:metadata:answer'] = 'Từng phần của một câu trả lời, ví dụ một từ hoặc một chỗ trống.';
$string['privacy:metadata:answer:answertext'] = 'Nội dung người tham gia đã nhập.';
$string['privacy:metadata:answer:iscorrect'] = 'Phần đó có đúng hay không.';
$string['privacy:metadata:answer:stars'] = 'Số sao cộng cho phần đó.';
$string['privacy:metadata:award'] = 'Sao do giảng viên tặng thủ công.';
$string['privacy:metadata:award:userid'] = 'Người nhận sao.';
$string['privacy:metadata:award:stars'] = 'Số sao được tặng.';
$string['privacy:metadata:award:reason'] = 'Lý do giảng viên ghi lại.';
$string['privacy:metadata:award:awardedby'] = 'Giảng viên đã tặng sao.';
$string['privacy:metadata:award:timecreated'] = 'Thời điểm tặng sao.';
$string['privacy:metadata:session'] = 'Một phiên trực tiếp đã chạy trên bộ slide.';
$string['privacy:metadata:session:createdby'] = 'Người phụ trách đã bắt đầu phiên.';
$string['privacy:metadata:session:name'] = 'Tên của phiên.';
$string['privacy:metadata:session:timecreated'] = 'Thời điểm bắt đầu phiên.';
$string['privacy:presenterpath'] = 'Các phiên đã trình chiếu';
$string['privacy:sessionpath'] = 'Phiên: {$a}';
