<?php


use App\Models\Purchase;
use Carbon\Carbon;
use Stichoza\GoogleTranslate\GoogleTranslate;

if (! function_exists('main_root')) {
    function main_root()
    {
        if (env('APP_ENV') == 'local') {
            return "/";
        }
        return '/public/';
    }
}

if (! function_exists('admin_file_root')) {
    function admin_file_root()
    {
        if (env('APP_ENV') == 'local') {
            return "/admin";
        }
        return "public/admin";
    }
}

if (! function_exists('frontend_file_root')) {
    function frontend_file_root()
    {
        return "public/frontend";
    }
}

if (! function_exists('admin')) {
    function admin()
    {
        return \Illuminate\Support\Facades\Auth::guard('admin');
    }
}

if (! function_exists('price')) {
    function price($price)
    {
        return 'R$' . number_format($price, 2);
    }
}

if (!function_exists('formatCurrency')) {
    /**
     * Formata um valor decimal como moeda brasileira.
     *
     * @param float|int|string $value O valor a ser formatado.
     * @return string
     */
    function formatCurrency($value)
    {
        if (is_null($value)) {
            return 'R$ 0,00';
        }

        return 'R$ ' . number_format((float)$value, 2, ',', '.');
    }
}

if (!function_exists('formatNewDateTime')) {
    /**
     * Formata uma data no formato dd/mm/YYYY H:mm.
     *
     * @param string|\DateTime|null $date A data a ser formatada.
     * @return string A data formatada ou um valor padrão se a data for inválida.
     */
    function formatNewDateTime($date)
    {
        if (!$date) {
            return 'Data inválida';
        }

        try {
            // Parse a data, define o idioma e converte para o timezone de São Paulo
            $date = Carbon::parse($date)->locale('pt_BR')->setTimezone('America/Sao_Paulo');
            $formattedDate = $date->format('d M g:i A');
            return $formattedDate;
        } catch (\Exception $e) {
            return 'Data inválida';
        }
    }
}
if (!function_exists('formatDateTime')) {
    /**
     * Formata uma data no formato dd/mm/YYYY H:mm.
     *
     * @param string|\DateTime|null $date A data a ser formatada.
     * @return string A data formatada ou um valor padrão se a data for inválida.
     */
    function formatDateTime($date)
    {
        if (!$date) {
            return 'Data inválida';
        }

        try {
            return Carbon::parse($date)->setTimezone('America/Sao_Paulo')->format('d/m/Y H:i');
        } catch (\Exception $e) {
            return 'Data inválida';
        }
    }
}

if (! function_exists('currency')) {
    function currency()
    {
        return 'R$';
    }
}

if (! function_exists('user')) {
    function user()
    {
        return \Illuminate\Support\Facades\Auth::user();
    }
}

if (! function_exists('not_found_img')) {
    function not_found_img()
    {
        return '/public/common/img/404.png';
    }
}

if (! function_exists('success_redirect')) {
    function success_redirect($route, $type)
    {
        return redirect()->route($route)->with('success', "Item $type Successfully.");
    }
}

if (! function_exists('error_redirect')) {
    function error_redirect($route, $type, $message)
    {
        return redirect()->route($route)->with($type, $message);
    }
}


if (! function_exists('uploadImage')) {
    function uploadImage($true_II_false_normal, $request, $input, $dir, $w = null, $h = null, $oldInput = null)
    {
        $path = public_path(str_replace('/public/', '', $oldInput));
        if ($request->hasFile($input)) {
            if (File::exists($path)) {
                File::delete($path);
            }
        }

        if ($request->hasFile($input)) {
            $file = $request->file($input);
            $namewithextension = $file->getClientOriginalName(); //Name with extension 'filename.jpg'
            $name = explode('.', $namewithextension)[0]; // Filename 'filename'
            $extension = $file->getClientOriginalExtension();
            $file_name = time() . Str::random(3) . '.' . $extension;

            if ($true_II_false_normal) {
                $destinationPath = public_path($dir);
                $imgFile = Image::make($request->file($input)->getRealPath());
                $imgFile->resize($w, $h, function ($constraint) {
                    $constraint->aspectRatio();
                })->save($destinationPath . '/' . $file_name);
            } else {
                $request->file($input)->move(public_path($dir), $file_name);
            }

            \Artisan::call('view:clear');
            \Artisan::call('cache:clear');
            $path = '/public/' . $dir . $file_name;
            return $path;
        }
        return null;
    }
}

if (! function_exists('deleteImage')) {
    function deleteImage($oldInput)
    {
        $path = public_path(str_replace('/public/', '', $oldInput));
        if (File::exists($path)) {
            File::delete($path);
        }
        return true;
    }
}

if (! function_exists('view_image')) {
    function view_image($imageName)
    {
        $mainUrl = env('IMAGE_VIEW_SET');
        if ($mainUrl == null) {
            $mainUrl = url('');
        }
        return '/public/storage/' . $imageName;
    }
}


if (! function_exists('createSlug')) {
    function createSlug($table_model, $title, $id = 0)
    {
        $slug = Str::slug($title);
        $allSlugs = getRelatedSlugs($table_model, $slug, $id);
        if (!$allSlugs->contains('slug', $slug)) {
            return $slug;
        }
        // Just append numbers like a savage until we find not used.
        for ($i = 1; $i <= 10; $i++) {
            $newSlug = $slug . '-' . $i;
            if (!$allSlugs->contains('slug', $newSlug)) {
                return $newSlug;
            }
        }
        throw new \Exception('Can not create a unique slug');
    }
}
//$object->slug = Helper::createSlug('\Category',$request->name);

if (! function_exists('getRelatedSlugs')) {
    function getRelatedSlugs($table_model, $slug, $id = 0)
    {
        $model_name = "App\Models" . $table_model;


        $data = $model_name::where('slug', 'like', $slug . '%')
            ->where('id', '<>', $id)
            ->get();
        return $data;
    }
}


if (! function_exists('setting')) {
    function setting($column, $default = null)
    {
        $setting = \App\Models\Setting::select($column)->first();
        return $setting ? $setting->{$column} : $default;
    }
}


if (! function_exists('my_vips')) {
    function my_vips()
    {
        $purchaseVips = Purchase::where('user_id', \auth()->id())->orderByDesc('id')->get();
        $vids = [];
        foreach ($purchaseVips as $el) {
            array_push($vids, $el->package_id);
        }
        return $vids;
    }
}

if (! function_exists('my_active_vips')) {
    function my_active_vips()
    {
        $purchaseVips = Purchase::where('user_id', \auth()->id())->where('status', 'active')->orderByDesc('id')->get();
        $vids = [];
        foreach ($purchaseVips as $el) {
            array_push($vids, $el->package_id);
        }
        return $vids;
    }
}


if (! function_exists('translator')) {
    function translator($text)
    {
        return $text;
        $text = GoogleTranslate::trans($text, app()->getLocale());
        return $text;
    }
}
if (! function_exists('registration_bonus')) {
    function registration_bonus()
    {
        return 25;
    }
}
if (! function_exists('registration_reword')) {
    function registration_reword()
    {
        return 15;
    }
}
