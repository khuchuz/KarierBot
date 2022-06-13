<?PHP
set_time_limit(0);
while(@ob_end_flush());
ob_implicit_flush(true);
date_default_timezone_set('Asia/Jakarta');
include_once 'config.php';
?>
<center>
<form method="post" enctype="multipart/form-data">
<h4>Masukkan list program_id</h4>
<textarea name="input" style="width: 777px; height: 222px;" placeholder="7519517, 7519558, 7519411"><?=(isset($_POST['input']) && !empty($_POST['input']))?$_POST['input']:'';?></textarea><br/>
<span>Delimiter : <input name="delimiter" value="|"/></span><br/>
<button type=submit>Submit</button>
</form>
<?PHP
if(isset($_POST['input']) && !empty($_POST['input'])){
	$input = $_POST['input'];
	$data = explode(',',trim(preg_replace('/\s\s+/', '', $input)));
	$list_uid = implode(',', $data);
	$stmt = $pdo->prepare("SELECT * FROM progress WHERE id IN (".$list_uid.")");
	$stmt->execute();
	$result = $stmt->fetchAll();
	$judul = array();
	$progress = array();
	$max_progress = 0;
	$min_progress = 100;
	foreach($result as $res){
		$judul[] = $res['judul'];
		if($res['percentage']>$max_progress) $max_progress=$res['percentage'];
		if($res['percentage']<$min_progress) $min_progress=$res['percentage'];
	}
	if(count(array_flip($judul)) === 1){
		if($max_progress-$min_progress<=10){
			echo "<center><a href='solve_multi.php?id=".$list_uid."'><h2>GAS</h2></a></center>";
		}else{
			echo "<center><h3>Progress berbeda lebih dari 10%</h3></center>";
		}
	}else{
		echo "<center><h3>Gagal, judul pelatihan tidak sama</h3></center>";
	}
}