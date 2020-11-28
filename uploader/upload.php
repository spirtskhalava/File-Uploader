<?php
	require '../vendor/autoload.php';

	function post($name){
		return (empty($_POST[$name])?null:$_POST[$name]);
	}

	$host='localhost';
	$dbname='upload';
	$username='root';
	$password='123456';
	$pdo=new PDO('mysql:host='.$host.';dbname='.$dbname,$username,$password);
	$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);

	$getFileFromDB=function ($id) use ($pdo){
		$query="select * from images where id=?";
		$prepared=$pdo->prepare($query);
		$prepared->execute([$id]);
		$fileInfo=$prepared->fetchAll(PDO::FETCH_ASSOC);
		if(!empty($fileInfo)){
			return $fileInfo[0];
		}
	};
	$addFileInDB=function ($name,$size,$extension,$mime,$real_name,$path) use ($pdo){
		$timestamp=date('Y/m/d H:i:s');
		$query="insert into images (
					name,
					size,
					extension,
					mime,
					real_name,
					upload_path,
					complete_at,
					resume_at,
					created_at,
					updated_at
				) values (
					?,?,?,?,?,?,null,?,?,?
				)";
	   	$prepared=$pdo->prepare($query);
		$prepared->execute([
			$name,
			$size,
			$extension,
			$mime,
			$real_name,
			$path,
			$timestamp,
			$timestamp,
			$timestamp
		]);
		return $pdo->lastInsertId();
	};

	$updateFileInDB=function ($id,$real_name,$path,$resumed,$completed=false) use ($pdo){
		$timestamp=date('Y/m/d H:i:s');
		$query="update images set
					real_name=?,
					upload_path=?,
					complete_at=?,
					resume_at=?,
					updated_at=?
				 where id=?";

		$completed = (empty($completed) ? null : $timestamp);
		$resumed = (empty($resumed) ? null : $timestamp);

	   	$prepared=$pdo->prepare($query);
		$prepared->execute([
			$real_name,
			$path,
			$completed,
			$resumed,
			$timestamp,
			$id
		]);
		return $prepared->rowCount();
	};
	$removeFileFromDB=function ($id) use ($pdo){
		$query="delete from images where id=?";
		$prepared=$pdo->prepare($query);
		$prepared->execute([$id]);
		return $prepared->rowCount();
	};
	$getFilesFromDB=function () use ($pdo){
		$query="select * from images";
		$result=$pdo->query($query);
		return $result->fetchAll(PDO::FETCH_ASSOC);
	};

	$action=post('action');

	switch($action){
		case 'delete':
			$id=post('id');
			$file=$getFileFromDB($id);
			$response=json_encode(["hasError"=>false,"error"=>"","delete"=>$id]);
			$address=$file['upload_path'].$file['real_name'].'.'.$file['extension'];
			if(file_exists($address)){
				@unlink($address);
			}
			$removeFileFromDB($id);
			echo $response;
			break;
		case 'add':
			$name=pathinfo(post('name'),PATHINFO_FILENAME);
			$size=post('size');
			$extension=pathinfo(post('name'),PATHINFO_EXTENSION);
			$mime=null;
			$real_name=null;
			$path=null;
			$id=$addFileInDB($name,$size,$extension,$mime,$real_name,$path);
			if(empty($id)){
				$response=json_encode(["hasError"=>true,"error"=>"file cant be added into database","id"=>null]);
			}else{
				$response=json_encode(["hasError"=>false,"error"=>"","id"=>$id]);
			}
			echo $response;
			break;
		case 'get':
			$id=post('id');
			$file=$getFileFromDB($id);
			$response=array("hasError"=>false,"error"=>"","data"=>null);
			$address=$file['upload_path'].$file['name'].'.'.$file['extension'];
			$size=0;
			if(file_exists($address)){
				$size=filesize($address);
			}
			$response["data"] = [
				'id' => intval($file['id']),
				'chunkPointer' => intval($size),
				'complete' => boolval($file['complete_at']),
				'pause' => !boolval($file['complete_at']),
				'address' =>$address,
				'ext' => $file['extension'],
				'file'=>[
					'name' => $file['name'].'.'.$file['extension'],
					'size' => intval($file['size']),
				]
			];
			echo json_encode($response,JSON_UNESCAPED_UNICODE);
			break;
		case 'upload':
			$id=post('id');
			$start=post('start');
			$end=post('end');
			$file=$getFileFromDB($id);
			$response=array("hasError"=>false,"error"=>"","progressPercent"=>intval(($end/$file['size'])*100).'%',"data"=>null);
			try{
				$uploadManager=new \UploadManager\Upload('data');
				$uploadManager->addValidations([
					new \UploadManager\Validations\Size('5G'),
					new \UploadManager\Validations\Extension(['jpg','jpeg','png','gif', 'bmp', 'mp3','mp4','avi','zip','docx','xlsx']),
				]);
				$uploadManager->afterValidate(function($chunk){
					$address=($chunk->getSavePath().$chunk->getNameWithExtension());
					if($chunk->hasError() && file_exists($address)){
						@unlink($address);
					}
				});
				$uploadManager->afterUpload(function($chunk) use ($file,$end,&$response,$updateFileInDB){
					$completed=($file['size']==$chunk->getSavedSize());
					$resumed= !$completed;

					$updateFileInDB($file['id'],$chunk->getName(),$chunk->getSavePath(),$resumed,$completed=false);

					$response["data"] = [
						'id' => intval($file['id']),
						'chunkPointer' => intval($chunk->getSavedSize()),
						'complete' => boolval($completed),
						'pause' => boolval($resumed),
						'address' =>'./uploads/'.basename($chunk->getSavePath().$chunk->getNameWithExtension()),
						'ext' => $chunk->getExtension(),
						'file'=>[
							'name' => $chunk->getNameWithExtension(),
							'size' => intval($file['size']),
						]
					];
				});

				if(empty($file['real_name'])){
					$real_name=$file['name'].'.'.$file['extension'];
					$chunks=$uploadManager->upload(
						'../uploads',
						$real_name,
						true,
						$start,
						$end - $start + 1
					);
				}else{
					$real_name=$file['real_name'].'.'.$file['extension'];
					$chunks=$uploadManager->upload(
						'../uploads',
						$real_name,
						false,
						$start,
						$end - $start + 1
					);
				}

			}catch(\UploadManager\Exceptions\Upload $exception){
				if(!empty($exception->getChunk())){
					$chunk=$exception->getChunk();
					$response=array("hasError"=>true,"error"=>$exception->getMessage());
				}else{
					$response=array("hasError"=>true,"error"=>$exception->getMessage());
				}
			}finally{
				echo json_encode($response,JSON_UNESCAPED_UNICODE);
			}
			break;
		default:
			$response=array();
			$files=$getFilesFromDB();
			if(!empty($files)){
				foreach($files as $file){
					$address=$file['upload_path'].$file['name'].'.'.$file['extension'];
					$size=0;
					if(file_exists($address)){
						$size=filesize($address);
					}
					$response[] = [
						'id' => intval($file['id']),
						'chunkPointer' => intval($size),
						'complete' => boolval($file['complete_at']),
						'pause' => boolval($file['resume_at']),
						'address' =>$address,
						'ext' => $file['extension'],
						'file'=>[
							'name' => $file['name'].'.'.$file['extension'],
							'size' => intval($file['size']),
						]
					];
				}
				echo json_encode($response,JSON_UNESCAPED_UNICODE);
			}
			break;
	}

